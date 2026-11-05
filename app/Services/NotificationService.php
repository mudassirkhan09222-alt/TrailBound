<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\TrailBoundNotification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class NotificationService
{
    /**
     * Send notification to one user.
     */
    public function send(
        User $recipient,
        array $data,
        ?User $actor = null
    ): void {
        // User ko apni activity ki notification nahi bhejni.
        if ($actor && (int) $recipient->id === (int) $actor->id) {
            return;
        }

        $data['actor_id'] = $actor?->id;
        $data['actor_name'] = $actor
            ? trim($actor->first_name . ' ' . $actor->last_name)
            : null;
        $data['actor_avatar'] = $actor?->profile_picture;

        $recipient->notify(new TrailBoundNotification($data));
    }

    /**
     * Send notification to multiple users.
     */
    public function sendToMany(
        iterable $recipients,
        array $data,
        ?User $actor = null
    ): void {
        foreach ($recipients as $recipient) {
            if ($recipient instanceof User) {
                $this->send($recipient, $data, $actor);
            }
        }
    }

    /**
     * Notify a user's followers about new content.
     */
    public function notifyFollowers(
        User $creator,
        string $type,
        string $title,
        string $message,
        int|string|null $relatedId = null,
        ?string $relatedType = null,
        ?string $url = null
    ): void {
        $data = [
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'related_id' => $relatedId,
            'related_type' => $relatedType,
            'url' => $url,
        ];

        $followers = $creator->followers()->get();

        $this->sendToMany($followers, $data, $creator);
    }
}
