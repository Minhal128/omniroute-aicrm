<?php

namespace App\Services\Channels\Drivers;

use App\Contracts\ChannelProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class FacebookMessengerDriver implements ChannelProviderInterface
{
    /**
     * Send outbound message via Meta's Graph API for Messenger.
     */
    public function sendMessage(array $credentials, array $messageData): array
    {
        // 1. Resolve Page Access Token flexibly across common credential schemas
        $pageAccessToken = $credentials['page_access_token']
            ?? $credentials['access_token']
            ?? $credentials['system_user_access_token']
            ?? $credentials['token']
            ?? null;

        $pageId = $credentials['page_id']
            ?? $credentials['facebook_page_id']
            ?? null;

        if (!$pageAccessToken) {
            throw new Exception("Facebook Messenger configuration access token (page_access_token or access_token) is missing.");
        }

        // 2. Mock / Sandbox environment handling
        if ($pageAccessToken === 'mock_token' || str_starts_with($pageAccessToken, 'mock_') || str_starts_with($pageAccessToken, 'test_')) {
            return [
                'external_message_id' => 'mock_fb_msg_' . uniqid(),
                'delivery_status' => 'sent',
                'error_message' => null,
            ];
        }

        // 3. Resolve Recipient ID
        $recipientId = $messageData['external_chat_id']
            ?? $messageData['to']
            ?? $messageData['recipient']
            ?? null;

        if (!$recipientId) {
            throw new Exception("Recipient external_chat_id is missing.");
        }

        $body = trim($messageData['body'] ?? $messageData['caption'] ?? $messageData['text'] ?? '');
        $mediaUrl = $messageData['media_url'] ?? $messageData['url'] ?? null;
        $messageType = $messageData['message_type'] ?? $messageData['type'] ?? null;

        if (empty($body) && empty($mediaUrl)) {
            return [
                'external_message_id' => null,
                'delivery_status' => 'failed',
                'error_message' => 'Message body or media URL is required.',
            ];
        }

        // 4. Meta Graph API Endpoint
        $url = "https://graph.facebook.com/v20.0/me/messages";

        try {
            $lastMessageId = null;

            // 5a. If media is present, send attachment payload first
            if (!empty($mediaUrl)) {
                $mediaType = 'image';
                if ($messageType && in_array($messageType, ['image', 'video', 'audio', 'file'])) {
                    $mediaType = $messageType;
                } else {
                    $ext = strtolower(pathinfo(parse_url($mediaUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                    if (in_array($ext, ['mp4', 'mov', 'avi'])) {
                        $mediaType = 'video';
                    } elseif (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'])) {
                        $mediaType = 'audio';
                    } elseif (in_array($ext, ['pdf', 'doc', 'docx', 'zip'])) {
                        $mediaType = 'file';
                    }
                }

                $mediaPayload = [
                    'recipient' => [
                        'id' => $recipientId,
                    ],
                    'messaging_type' => 'RESPONSE',
                    'message' => [
                        'attachment' => [
                            'type' => $mediaType,
                            'payload' => [
                                'url' => $mediaUrl,
                                'is_reusable' => true,
                            ],
                        ],
                    ],
                ];

                $mediaResponse = Http::withToken($pageAccessToken)
                    ->timeout(15)
                    ->post($url, $mediaPayload);

                if (!$mediaResponse->successful()) {
                    Log::error("Facebook Messenger API media send error: " . $mediaResponse->body());
                    return [
                        'external_message_id' => null,
                        'delivery_status' => 'failed',
                        'error_message' => $mediaResponse->json('error.message') ?? 'Meta API error while sending media attachment.',
                    ];
                }

                $lastMessageId = $mediaResponse->json('message_id');
            }

            // 5b. If text body is present, send text payload
            if (!empty($body)) {
                $textPayload = [
                    'recipient' => [
                        'id' => $recipientId,
                    ],
                    'messaging_type' => 'RESPONSE',
                    'message' => [
                        'text' => $body,
                    ],
                ];

                $textResponse = Http::withToken($pageAccessToken)
                    ->timeout(10)
                    ->post($url, $textPayload);

                if (!$textResponse->successful()) {
                    Log::error("Facebook Messenger API text send error: " . $textResponse->body());
                    return [
                        'external_message_id' => $lastMessageId,
                        'delivery_status' => $lastMessageId ? 'sent' : 'failed',
                        'error_message' => $textResponse->json('error.message') ?? 'Meta API error while sending text message.',
                    ];
                }

                $lastMessageId = $textResponse->json('message_id') ?? $lastMessageId;
            }

            return [
                'external_message_id' => $lastMessageId ?? ('fb_msg_' . uniqid()),
                'delivery_status' => 'sent',
                'error_message' => null,
            ];
        } catch (Exception $e) {
            Log::error("Facebook Messenger sendMessage failed: " . $e->getMessage());
            return [
                'external_message_id' => null,
                'delivery_status' => 'failed',
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify authenticity of webhook signature.
     */
    public function verifyWebhookSignature(array $headers, string $payload, array $credentials): bool
    {

        $appSecret = !empty($credentials['webhook_secret']) ? $credentials['webhook_secret'] : (!empty($credentials['app_secret']) ? $credentials['app_secret'] : '');
        if (empty($appSecret)) {
            $savedSetting = \App\Models\PlatformSetting::getSetting('meta_embedded_config');
            $appSecret = is_array($savedSetting) ? ($savedSetting['app_secret'] ?? '') : '';
        }

        if (empty($appSecret)) {
            if (app()->environment('production')) {
                Log::error("Facebook Messenger webhook verification failed: App Secret is not configured in production.");
                return false;
            }
            Log::warning("Facebook Messenger webhook verification skipped in local environment: App Secret is empty.");
            return true;
        }

        $hubSig = $headers['x-hub-signature-256'] ?? $headers['X-Hub-Signature-256'] ?? '';
        if (empty($hubSig)) {
            return false;
        }

        $parts = explode('=', $hubSig);
        if (count($parts) !== 2 || $parts[0] !== 'sha256') {
            return false;
        }

        $expectedSig = hash_hmac('sha256', $payload, $appSecret);
        return hash_equals($expectedSig, $parts[1]);
    }

    /**
     * Normalize incoming webhook payload into a unified internal schema.
     */
    public function normalizeInboundPayload(array $payload): array
    {

        // Check for direct flat schema (e.g. for mock testing or internal dispatchers)
        if (isset($payload['external_chat_id'])) {
            return [
                'external_chat_id' => (string)$payload['external_chat_id'],
                'sender_identifier' => $payload['sender_identifier'] ?? 'Facebook User',
                'external_message_id' => (string)($payload['external_message_id'] ?? 'fb_msg_' . uniqid()),
                'message_type' => $payload['message_type'] ?? 'text',
                'body' => $payload['body'] ?? '',
                'media_url' => $payload['media_url'] ?? null,
            ];
        }

        // Standard Meta Webhook format
        $messaging = $payload['entry'][0]['messaging'][0] ?? [];
        $senderId = $messaging['sender']['id'] ?? 'unknown_fb_user';
        $message = $messaging['message'] ?? [];
        $mid = $message['mid'] ?? 'fb_msg_' . uniqid();
        $text = $message['text'] ?? '';

        // Handle Quick Reply text payload if present
        if (isset($message['quick_reply']['payload'])) {
            $text = $message['quick_reply']['payload'];
        }
        
        $mediaUrl = null;
        $msgType = 'text';
        if (isset($message['attachments'][0])) {
            $attachment = $message['attachments'][0];
            $msgType = $attachment['type'] ?? 'text';
            $mediaUrl = $attachment['payload']['url'] ?? null;
        }

        return [
            'external_chat_id' => (string)$senderId,
            'sender_identifier' => 'Facebook User ' . substr($senderId, -4),
            'external_message_id' => (string)$mid,
            'message_type' => $msgType,
            'body' => $text,
            'media_url' => $mediaUrl,
        ];
    }

    /**
     * Normalize delivery status update webhook payload.
     */
    public function normalizeStatusPayload(array $payload): array
    {
        if (isset($payload['external_message_id'])) {
            return [
                'external_message_id' => (string)$payload['external_message_id'],
                'delivery_status' => $payload['delivery_status'] ?? 'delivered',
                'error_message' => $payload['error_message'] ?? null,
            ];
        }

        // Parse official Meta status payload if any
        $statuses = $payload['entry'][0]['changes'][0]['value']['statuses'][0] ?? [];
        $mid = $statuses['id'] ?? '';
        $status = $statuses['status'] ?? 'delivered';

        return [
            'external_message_id' => (string)$mid,
            'delivery_status' => $status,
            'error_message' => $statuses['errors'][0]['message'] ?? null,
        ];
    }
}
