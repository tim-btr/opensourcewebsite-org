<?php

namespace app\modules\bot\controllers\privates;

use app\modules\bot\components\Controller;
use app\modules\bot\components\helpers\Emoji;
use app\modules\bot\components\helpers\MessageWithEntitiesConverter;
use app\modules\bot\models\Chat;
use app\modules\bot\models\ChatSetting;
use Yii;

/**
 * Class GroupGreetingController
 *
 * @package app\modules\bot\controllers\privates
 */
class GroupGreetingController extends Controller
{
    /**
     * @return array
     */
    public function actionIndex($chatId = null)
    {
        $chat = Chat::findOne($chatId);
        $telegramUser = $this->getTelegramUser();

        if (!isset($chat)) {
            return [];
        }

        $this->getState()->setName(null);

        $statusOn = ($chat->greeting_status == ChatSetting::STATUS_ON);

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('index', compact('chat', 'telegramUser')),
                [
                    [
                        [
                            'callback_data' => self::createRoute('set-status', [
                                'chatId' => $chatId,
                            ]),
                            'text' => $statusOn ? Emoji::STATUS_ON . ' ON' : Emoji::STATUS_OFF . ' OFF',
                        ],
                    ],
                    [
                        [
                            'callback_data' => self::createRoute('set-message', [
                                'chatId' => $chatId,
                            ]),
                            'text' => Yii::t('bot', 'Message'),
                        ],
                    ],
                    [
                        [
                            'callback_data' => GroupController::createRoute('view', [
                                'chatId' => $chatId,
                            ]),
                            'text' => Emoji::BACK,
                        ],
                        [
                            'callback_data' => MenuController::createRoute(),
                            'text' => Emoji::MENU,
                        ],
                    ],
                ],
                [
                    'disablePreview' => true,
                ]
            )
            ->build();
    }

    public function actionSetStatus($chatId = null)
    {
        $chat = Chat::findOne($chatId);

        if (!isset($chat)) {
            return [];
        }

        if ($chat->greeting_status == ChatSetting::STATUS_ON) {
            $chat->greeting_status = ChatSetting::STATUS_OFF;
        } else {
            $chat->greeting_status = ChatSetting::STATUS_ON;
        }

        return $this->actionIndex($chatId);
    }

    public function actionSetMessage($chatId = null)
    {
        $chat = Chat::findOne($chatId);

        if (!isset($chat)) {
            return [];
        }

        $this->getState()->setName(self::createRoute('set-message', [
                'chatId' => $chatId,
            ]));

        if ($this->getUpdate()->getMessage()) {
            if ($text = MessageWithEntitiesConverter::toHtml($this->getUpdate()->getMessage())) {
                if ($chat->validateSettingValue('greeting_message', $text)) {
                    $chat->greeting_message = $text;

                    return $this->runAction('index', [
                         'chatId' => $chatId,
                     ]);
                }
            }
        }

        $messageMarkdown = MessageWithEntitiesConverter::fromHtml($chat->greeting_message ?? '');

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('set-message', [
                    'messageMarkdown' => $messageMarkdown,
                ]),
                [
                    [
                        [
                            'callback_data' => self::createRoute('index', [
                                'chatId' => $chatId,
                            ]),
                            'text' => Emoji::BACK,
                        ],
                    ],
                ],
                [
                    'disablePreview' => true,
                ]
            )
            ->build();
    }
}
