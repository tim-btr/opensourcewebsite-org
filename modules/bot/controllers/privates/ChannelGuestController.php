<?php

namespace app\modules\bot\controllers\privates;

use Yii;
use app\modules\bot\components\Controller;
use app\modules\bot\components\helpers\PaginationButtons;
use app\modules\bot\models\Chat;
use app\modules\bot\models\ChatSetting;
use yii\data\Pagination;
use app\modules\bot\components\helpers\Emoji;

/**
 * Class ChannelGuestController
 *
 * @package app\modules\bot\controllers\privates
 */
class ChannelGuestController extends Controller
{
    /**
     * @return array
     */
    public function actionView($chatId = null)
    {
        $chat = Chat::findOne($chatId);

        if (!isset($chat) || (!$chat->isChannel())) {
            return [];
        }

        $this->getState()->setName(null);

        return $this->getResponseBuilder()
            ->editMessageTextOrSendMessage(
                $this->render('view', [
                    'chat' => $chat,
                ]),
                [
                    [
                        [
                            'callback_data' => ChannelGuestMarketplaceController::createRoute('index', [
                                'chatId' => $chat->id,
                            ]),
                            'text' => Yii::t('bot', 'Marketplace'),
                            'visible' => ($chat->marketplace_status == ChatSetting::STATUS_ON),
                        ],
                    ],
                    [
                        [
                            'callback_data' => MenuController::createRoute(),
                            'text' => Emoji::MENU,
                        ],
                    ]
                ]
            )
            ->build();
    }
}
