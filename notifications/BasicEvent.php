<?php

namespace app\modules\notification\events;

use app\modules\notification\channels\PersonalChannel;
use app\modules\notification\messages\BroadcastMessage;
use app\modules\notification\models\Notification;
use app\modules\notification\models\UserNotification;
use mkiselev\broadcasting\events\BroadcastEvent;

abstract class BasicEvent extends BroadcastEvent
{
    public int $broadcaster = self::BROADCASTER_SOCKET_AND_DB;
    public array $userIds;
    public int $moduleId;
    public bool $isNeedReaction = false;
    public BroadcastMessage $message;
    public ?int $categoryId;
    public ?int $typeId;


    protected ?int $_statusId;


    public const BROADCASTER_SOCKET = 1;
    public const BROADCASTER_DB = 2;
    public const BROADCASTER_SOCKET_AND_DB = 3;

    // Getters and setters

    public function setStatusId($statusId)
    {
        $this->_statusId = $statusId;
    }


    public function getStatusId(): ?int
    {
        return $this->_statusId ;
    }

    // Event logic

    public function broadcastOn(): array
    {
        return $this->broadcaster === self::BROADCASTER_DB ? [] : $this->shareToUserChannels();
    }


    public function broadcastWith(): array
    {
        $this->setStatusId(UserNotification::STATUS_UNREAD);

        if ($this->broadcaster === self::BROADCASTER_DB || $this->broadcaster === self::BROADCASTER_SOCKET_AND_DB) {
            $this->broadcastToDb();
        }

        return [
            'moduleId' => $this->moduleId,
            'categoryId' => $this->categoryId,
            'typeId' => $this->typeId,
            'isNeedReaction' => $this->isNeedReaction,
            'statusId' => $this->getStatusId(),
            'message' => $this->message,
        ];
    }


    /**
     * Opens the PersonalChannel for each user-recipient.
     *
     * @return array of users PersonalChannels
     */
    protected function shareToUserChannels(): array
    {
        $channels = [];

        foreach ($this->userIds as $userId) {
            $channels[] = new PersonalChannel($userId);
        }

        return $channels;
    }


    /**
     * Needs to send Notification data to DB.
     * 
     * @return void
     */
    protected function broadcastToDb()
    {
        $notificationModel = new Notification();
        $notificationModel->categoryId = $this->categoryId;
        $notificationModel->typeId = $this->typeId;
        $notificationModel->textKey = $this->message->textKey;

        if ($notificationModel->save()) {
            foreach ($this->userIds as $userId) {
                $this->chainNotification($notificationModel->id, $userId);
            }
        }
    }


    private function chainNotification(int $notificationId, $userId)
    {
        $userNotificationModel = new UserNotification();
        $userNotificationModel->notificationId = $notificationId;
        $userNotificationModel->userId = $userId;
        $userNotificationModel->statusId = UserNotification::STATUS_UNREAD;

        $userNotificationModel->save();
    }
}
