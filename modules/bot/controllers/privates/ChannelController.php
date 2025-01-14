<?php

// TODO

namespace app\modules\bot\controllers\privates;

use Yii;
use app\modules\bot\components\Controller;
use app\modules\bot\components\helpers\Emoji;
use yii\data\Pagination;
use app\modules\bot\components\helpers\PaginationButtons;
use app\modules\bot\models\Chat;
use app\modules\bot\models\ChatMember;
use app\modules\bot\models\ChatSetting;
use app\modules\bot\models\User;
use yii\helpers\ArrayHelper;

/**
 * Class ChannelController
 *
 * @package app\modules\bot\controllers\privates
 */
class ChannelController extends Controller
{
    /**
     * @return array
     */
    public function actionIndex($page = 1)
    {
        $this->getState()->setName(null);

        $chatQuery = $this->getTelegramUser()->getAdministratedChannels();

        $pagination = new Pagination([
            'totalCount' => $chatQuery->count(),
            'pageSize' => 9,
            'params' => [
                'page' => $page,
            ],
        ]);

        $pagination->pageSizeParam = false;
        $pagination->validatePage = true;

        $chats = $chatQuery->offset($pagination->offset)
            ->limit($pagination->limit)
            ->all();

        $paginationButtons = PaginationButtons::build($pagination, function ($page) {
            return self::createRoute('index', [
                'page' => $page,
            ]);
        });

        $buttons = [];

        if ($chats) {
            foreach ($chats as $chat) {
                $buttons[][] = [
                    'callback_data' => ChannelController::createRoute('view', [
                        'chatId' => $chat->id,
                    ]),
                    'text' => $chat->title,
                ];
            }

            if ($paginationButtons) {
                $buttons[] = $paginationButtons;
            }
        }

        $buttons[] = [
            [
                'callback_data' => TelegramController::createRoute(),
                'text' => Emoji::BACK,
            ],
            [
                'callback_data' => MenuController::createRoute(),
                'text' => Emoji::MENU,
            ],
        ];

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('index'),
                $buttons
            )
            ->build();
    }

    /**
     * @return array
     */
    public function actionView($chatId = null)
    {
        $this->getState()->setName(null);

        if ($chatId) {
            $chat = Chat::findOne($chatId);

            if (!isset($chat)) {
                return $this->getResponseBuilder()
                    ->answerCallbackQuery()
                    ->build();
            }

            $telegramUser = $this->getTelegramUser();

            $chatMember = ChatMember::findOne([
                'chat_id' => $chat->id,
                'user_id' => $telegramUser->id,
            ]);

            if (!isset($chatMember)) {
                return $this->getResponseBuilder()
                    ->answerCallbackQuery()
                    ->build();
            }

            // TODO refactoring, для того чтобы ограничить доступ к настройкам группы
            if ($this->getUpdate()->getCallbackQuery()) {
                $admins = $chat->getAdministrators()->all();

                return $this->getResponseBuilder()
                    ->editMessageTextOrSendMessage(
                        $this->render('view', [
                            'chat' => $chat,
                            'admins' => $admins,
                        ]),
                        [
                            [
                                [
                                    'callback_data' => self::createRoute('administrators', [
                                        'chatId' => $chat->id,
                                    ]),
                                    'text' => Yii::t('bot', 'Administrators'),
                                    'visible' => $chatMember->isCreator(),
                                ],
                            ],
                            [
                                [
                                    'callback_data' => ChannelMarketplaceController::createRoute('index', [
                                        'chatId' => $chat->id,
                                    ]),
                                    'text' => call_user_func(
                                        function () use ($chat) {
                                            $statusOn = ($chat->marketplace_status == ChatSetting::STATUS_ON);

                                            return ($statusOn ? Emoji::STATUS_ON : Emoji::STATUS_OFF) . ' ' . Yii::t('bot', 'Marketplace');
                                        }
                                    ),
                                ],
                            ],
                            [
                                [
                                    'callback_data' => ChannelController::createRoute(),
                                    'text' => Emoji::BACK,
                                ],
                                [
                                    'callback_data' => MenuController::createRoute(),
                                    'text' => Emoji::MENU,
                                ],
                                [
                                    'callback_data' => ChannelRefreshController::createRoute('index', [
                                        'chatId' => $chat->id,
                                    ]),
                                    'text' => Emoji::REFRESH,
                                ],
                            ],
                        ]
                    )
                    ->build();
            }

            return [];
        }
    }

    /**
     * @param int $page
     * @param int|null $chatId
     * @return array
     */
    public function actionAdministrators($page = 1, $chatId = null)
    {
        $this->getState()->setName(null);

        if ($chatId) {
            $chat = Chat::findOne($chatId);

            if (!isset($chat)) {
                return $this->getResponseBuilder()
                    ->answerCallbackQuery()
                    ->build();
            }

            $telegramUser = $this->getTelegramUser();

            $chatMember = ChatMember::findOne([
                'chat_id' => $chat->id,
                'user_id' => $telegramUser->id,
            ]);

            if (!isset($chatMember) || !$chatMember->isCreator()) {
                return $this->getResponseBuilder()
                    ->answerCallbackQuery()
                    ->build();
            }

            $query = $chat->getAdministrators();

            $pagination = new Pagination([
                'totalCount' => $query->count(),
                'pageSize' => 9,
                'params' => [
                    'page' => $page,
                ],
            ]);

            $pagination->pageSizeParam = false;
            $pagination->validatePage = true;

            $administrators = $query->offset($pagination->offset)
                ->limit($pagination->limit)
                ->all();

            $paginationButtons = PaginationButtons::build($pagination, function ($page) {
                return self::createRoute('index', [
                    'page' => $page,
                ]);
            });

            $buttons = [];

            if ($administrators) {
                foreach ($administrators as $botUser) {
                    $administratorChatMember = $chat->getChatMemberByUser($botUser);

                    $buttons[][] = [
                        'callback_data' => self::createRoute('set-administrator', [
                            'chatId' => $chatId,
                            'administratorId' => $botUser->id,
                        ]),
                        'text' => ($administratorChatMember->status == ChatMember::STATUS_CREATOR ? Emoji::CROWN : ($administratorChatMember->role == ChatMember::ROLE_ADMINISTRATOR ? Emoji::STATUS_ON : Emoji::STATUS_OFF)) . ' ' . $botUser->getFullName() . ($botUser->provider_user_name ? ' @' . $botUser->provider_user_name : ''),
                    ];
                }

                if ($paginationButtons) {
                    $buttons[] = $paginationButtons;
                }
            }

            $buttons[] = [
                [
                    'callback_data' => ChannelController::createRoute('view', [
                        'chatId' => $chatId,
                    ]),
                    'text' => Emoji::BACK,
                ],
                [
                    'callback_data' => MenuController::createRoute(),
                    'text' => Emoji::MENU,
                ]
            ];

            return $this->getResponseBuilder()
                ->editMessageTextOrSendMessage(
                    $this->render('administrators', [
                        'chat' => $chat,
                    ]),
                    $buttons
                )
                ->build();
        }
    }

    // TODO remove this action and join it to 'administrators' action to display the current page correctly
    public function actionSetAdministrator($chatId = null, $administratorId = null)
    {
        $this->getState()->setName(null);

        $chat = Chat::findOne($chatId);

        if (!isset($chat)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $telegramUser = $this->getTelegramUser();

        $chatMember = ChatMember::findOne([
            'chat_id' => $chat->id,
            'user_id' => $telegramUser->id,
        ]);

        // creator cannot be deactivated
        if (!isset($chatMember) || !$chatMember->isCreator() || ($chatMember->getUserId() == $administratorId)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        $administratorChatMember = ChatMember::findOne([
            'chat_id' => $chat->id,
            'user_id' => $administratorId,
        ]);

        if (!isset($administratorChatMember)) {
            return $this->getResponseBuilder()
                ->answerCallbackQuery()
                ->build();
        }

        if ($administratorChatMember->isActiveAdministrator()) {
            $administratorChatMember->role = ChatMember::ROLE_MEMBER;
        } else {
            $administratorChatMember->role = ChatMember::ROLE_ADMINISTRATOR;
        }

        $administratorChatMember->save();

        return $this->runAction('administrators', [
             'chatId' => $chatId,
         ]);
    }
}
