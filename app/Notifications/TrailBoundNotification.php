<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TrailBoundNotification extends Notification
{
    use Queueable;

    protected array $notificationData;

    public function __construct(array $data)
    {
        $this->notificationData = $data;
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->notificationData['title'] ?? 'TrailBound',

            'message' => $this->notificationData['message'] ?? '',

            'type' => $this->notificationData['type'] ?? 'general',

            'actor_id' => $this->notificationData['actor_id'] ?? null,

            'actor_name' => $this->notificationData['actor_name'] ?? null,

            'actor_avatar' => $this->notificationData['actor_avatar'] ?? null,

            'related_id' => $this->notificationData['related_id'] ?? null,

            'related_type' => $this->notificationData['related_type'] ?? null,

            'url' => $this->notificationData['url'] ?? null,
        ];
    }
}
