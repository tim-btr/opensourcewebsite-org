<?php

namespace app\models;

use app\models\events\interfaces\ViewedByUserInterface;
use app\models\events\ViewedByUserEvent;
use app\modules\bot\components\helpers\LocationParser;
use app\modules\bot\validators\LocationLatValidator;
use app\modules\bot\validators\LocationLonValidator;
use app\modules\bot\validators\RadiusValidator;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\Expression;
use yii\helpers\ArrayHelper;
use yii\web\JsExpression;
use app\models\queries\CurrencyExchangeOrderQuery;
use app\models\matchers\ModelLinker;
use app\components\helpers\Html;
use app\models\scenarios\CurrencyExchangeOrder\UpdateScenario;
use app\models\scenarios\CurrencyExchangeOrder\UpdateSellingPaymentMethodsByIdsScenario;

/**
 * This is the model class for table "currency_exchange_order".
 *
 * @property int $id
 * @property int $user_id
 * @property int $selling_currency_id
 * @property int $buying_currency_id
 * @property float|null $fee
 * @property float|null $selling_currency_min_amount
 * @property float|null $selling_currency_max_amount
 * @property int $status
 * @property int $selling_delivery_radius
 * @property string|null $selling_location_lat
 * @property string|null $selling_location_lon
 * @property int $buying_delivery_radius
 * @property string|null $buying_location_lat
 * @property string|null $buying_location_lon
 * @property int $created_at
 * @property int|null $processed_at
 * @property int $selling_cash_on
 * @property int $buying_cash_on
 * @property string $selling_currency_label
 * @property string $buying_currency_label
 *
 * @property User $user
 * @property CurrencyExchangeOrderBuyingPaymentMethod[] $currencyExchangeOrderBuyingPaymentMethods
 * @property CurrencyExchangeOrderMatch[] $currencyExchangeOrderMatches
 * @property CurrencyExchangeOrderMatch[] $currencyExchangeOrderMatches0
 * @property CurrencyExchangeOrderSellingPaymentMethod[] $currencyExchangeOrderSellingPaymentMethods
 * @property string $selling_location
 * @property string $buying_location
 */
class CurrencyExchangeOrder extends ActiveRecord implements ViewedByUserInterface
{
    public const STATUS_OFF = 0;
    public const STATUS_ON = 1;

    public const LIVE_DAYS = 30;

    public const CASH_OFF = 0;
    public const CASH_ON = 1;

    public const EVENT_SELLING_PAYMENT_METHODS_UPDATED = 'sellingPaymentMethodsUpdated';
    public const EVENT_BUYING_PAYMENT_METHODS_UPDATED = 'buyingPaymentMethodsUpdated';

    public $sellingPaymentMethodIds = [];
    public $buyingPaymentMethodIds = [];

    public function init()
    {
        $this->on(self::EVENT_SELLING_PAYMENT_METHODS_UPDATED, [$this, 'clearMatches']);
        $this->on(self::EVENT_BUYING_PAYMENT_METHODS_UPDATED, [$this, 'clearMatches']);
        $this->on(self::EVENT_VIEWED_BY_USER, [$this, 'markViewedByUser']);

        parent::init();
    }

    public function markViewedByUser(ViewedByUserEvent $event)
    {
        $response = CurrencyExchangeOrderResponse::findOrNewResponse($event->user->id, $this->id);
        $response->viewed_at = time();
        $response->save();
    }

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'currency_exchange_order';
    }

    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        return [
            [['user_id', 'selling_currency_id', 'buying_currency_id'], 'required'],
            [['user_id', 'selling_currency_id', 'buying_currency_id', 'status', 'selling_delivery_radius', 'buying_delivery_radius', 'created_at', 'processed_at'], 'integer'],
            [['selling_cash_on', 'buying_cash_on'], 'boolean'],
            [['selling_delivery_radius', 'buying_delivery_radius'], RadiusValidator::class],
            [['selling_location_lat', 'buying_location_lat'], LocationLatValidator::class],
            [['selling_location_lon', 'buying_location_lat'], LocationLonValidator::class],
            ['selling_location', 'required', 'when' => function ($model) {
                if ($model->selling_cash_on && ! $model->selling_location) {
                    return true;
                }
                return false;
            }, 'whenClient' => new JsExpression("function(attribute, value) {
                    return $('#cashSellCheckbox').prop('checked');
                }")
            ],
            [
                'buying_location',
                'required',
                'when' => function ($model) {
                    if ($model->buying_cash_on && ! $model->buying_location) {
                        return true;
                    }
                    return false;
                },
                'whenClient' => new JsExpression("function(attribute, value) {
                        return $('#cashBuyCheckbox').prop('checked');
                    }")
            ],
            [
                [
                    'selling_location',
                    'buying_location'
                ],
                function ($attribute) {
                    [$lat, $lon] = (new LocationParser($this->$attribute))->parse();
                    if (!(new LocationLatValidator())->validateLat($lat) ||
                        !(new LocationLonValidator())->validateLon($lon)
                    ) {
                        $this->addError($attribute, Yii::t('app', 'Incorrect Location!'));
                    }
                }
            ],
            [
                [
                    'selling_location',
                    'buying_location',
                    'selling_currency_label',
                    'buying_currency_label',
                ],
                'string',
                'max' => 255,
            ],
            [
                [
                    'fee',
                ],
                'double',
                'min' => -99.99999999,
                'max' => 99.99999999,
            ],
            [
                'fee',
                'default',
                'value' => 0,
            ],
            [
                [
                    'selling_currency_min_amount',
                    'selling_currency_max_amount',
                ],
                'filter', 'filter' => function ($value) {
                    return ($value != 0 ? $value : null);
                },
            ],
            [
                [
                    'selling_currency_min_amount',
                    'selling_currency_max_amount',
                ],
                'double',
                'min' => 0,
                'max' => 9999999999.99999999,
            ],
            [
                [
                    'selling_currency_max_amount',
                ],
                'compare',
                'when' => function ($model) {
                    return $model->selling_currency_min_amount != null;
                },
                'whenClient' => new JsExpression(
                    "
                    function (attribute, value) {
                        return $('#currencyexchangeorder-selling_currency_min_amount').val() != ''
                    }"
                ),
                'compareAttribute' => 'selling_currency_min_amount', 'operator' => '>=', 'type' => 'number'
            ],
            [
                ['sellingPaymentMethodIds', 'buyingPaymentMethodIds'],
                'filter', 'filter' => function ($val) {
                    if ($val === '') {
                        return [];
                    }
                    return $val;
                }
            ],
            [['sellingPaymentMethodIds', 'buyingPaymentMethodIds'], 'each', 'rule' => ['integer']],
            // [
            //     'buyingPaymentMethodIds', 'filter', 'filter' => function ($val) {
            //         if ($val === '') {
            //             return [];
            //         }
            //         return $val;
            //     }
            // ],
            // [
            //     'buyingPaymentMethodIds', 'each', 'rule' => ['integer'],
            // ],
        ];
    }

    public static function find(): CurrencyExchangeOrderQuery
    {
        return new CurrencyExchangeOrderQuery(get_called_class());
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'user_id' => 'User ID',
            'selling_currency_id' => Yii::t('bot', 'Selling Currency'),
            'buying_currency_id' => Yii::t('bot', 'Buying Currency'),
            'fee' => Yii::t('bot', 'Fee'),
            'selling_currency_min_amount' => Yii::t('bot', 'Min. amount'),
            'selling_currency_max_amount' => Yii::t('bot', 'Max. amount'),
            'status' => Yii::t('bot', 'Status'),
            'selling_delivery_radius' => Yii::t('bot', 'Selling delivery radius'),
            'buying_delivery_radius' => Yii::t('bot', 'Buying delivery radius'),
            'selling_location_lat' => 'Location Lat',
            'selling_location_lon' => 'Location Lon',
            'buying_location_lat' => 'Location Lat',
            'buying_location_lon' => 'Location Lon',
            'created_at' => 'Created At',
            'processed_at' => 'Processed At',
            'selling_cash_on' => Yii::t('bot', 'Cash'),
            'buying_cash_on' => Yii::t('bot', 'Cash'),
            'selling_currency_label' => Yii::t('app', 'Label'),
            'buying_currency_label' => Yii::t('app', 'Label'),
            'sellingPaymentMethodIds' => Yii::t('app', 'Selling payment methods'),
            'buyingPaymentMethodIds' => Yii::t('app', 'Buying payment methods'),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeHints()
    {
        return [
            'fee' => Yii::t('app', 'Cross rate is the current international exchange rate') . '. ' . Yii::t('app', 'Fee is added to the cross rate') . '. ' . Yii::t('app', 'Fee is zero by default and can be positive (you get fee) or negative (you give fee)') . '.',
            'selling_currency_min_amount' => Yii::t('app', 'Minimum amount limit of selling currency in one trade') .  '.',
            'selling_currency_max_amount' => Yii::t('app', 'Maximum amount limit of selling currency in one trade') .  '.',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'timestamp' => [
                'class' => TimestampBehavior::class,
                'updatedAtAttribute' => false,
            ],
        ];
    }

    public function setSelling_location(string $location): self
    {
        [$lat, $lon] = (new LocationParser($location))->parse();
        $this->selling_location_lat = $lat;
        $this->selling_location_lon = $lon;
        return $this;
    }

    public function getSelling_location(): string
    {
        return ($this->selling_location_lat && $this->selling_location_lon) ?
            implode(',', [$this->selling_location_lat, $this->selling_location_lon]) :
            '';
    }

    public function setBuying_location(string $location): self
    {
        [$lat, $lon] = (new LocationParser($location))->parse();
        $this->buying_location_lat = $lat;
        $this->buying_location_lon = $lon;
        return $this;
    }

    public function getBuying_location(): string
    {
        return ($this->buying_location_lat && $this->buying_location_lon) ?
            implode(',', [$this->buying_location_lat, $this->buying_location_lon]) :
            '';
    }

    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * Gets query for [[CurrencyExchangeOrderSellingPaymentMethods]].
     *
     * @return \yii\db\ActiveQuery
     * @throws \yii\base\InvalidConfigException
     */
    public function getSellingPaymentMethods(): ActiveQuery
    {
        return $this->hasMany(PaymentMethod::class, ['id' => 'payment_method_id'])
            ->viaTable('{{%currency_exchange_order_selling_payment_method}}', ['order_id' => 'id']);
    }

    /**
     * Gets query for [[CurrencyExchangeOrderBuyingPaymentMethods]].
     *
     * @return \yii\db\ActiveQuery
     * @throws \yii\base\InvalidConfigException
     */
    public function getBuyingPaymentMethods(): ActiveQuery
    {
        return $this->hasMany(PaymentMethod::class, ['id' => 'payment_method_id'])
            ->viaTable('{{%currency_exchange_order_buying_payment_method}}', ['order_id' => 'id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     * @throws \yii\base\InvalidConfigException
     */
    public function getMatches(): ActiveQuery
    {
        return $this->hasMany(self::class, ['id' => 'match_order_id'])
            ->viaTable('{{%currency_exchange_order_match}}', ['order_id' => 'id']);
    }

    public function getMatchesCount()
    {
        return $this->hasMany(CurrencyExchangeOrderMatch::class, ['order_id' => 'id'])
            ->count();
    }

    /**
     * @return \yii\db\ActiveQuery
     * @throws \yii\base\InvalidConfigException
     */
    // TODO new matches
    public function getNewMatches(): ActiveQuery
    {
        return $this->hasMany(self::class, ['id' => 'match_order_id'])
            ->viaTable('{{%currency_exchange_order_match}}', ['order_id' => 'id']);
    }

    public function getNewMatchesCount()
    {
        return $this->hasMany(CurrencyExchangeOrderMatch::class, ['order_id' => 'id'])
            ->andWhere([
                'not in',
                'match_order_id',
                CurrencyExchangeOrderResponse::find()
                    ->select('order_id')
                    ->andWhere([
                        'user_id' => Yii::$app->user->id,
                    ])
                    ->andWhere([
                        'is not', 'viewed_at', null,
                    ]),
            ])
            ->count();
    }

    public function isNewMatch()
    {
        return !(bool)CurrencyExchangeOrderResponse::find()
            ->andWhere([
                'user_id' => Yii::$app->user->id,
                'order_id' => $this->id,
            ])
            ->andWhere([
                'is not', 'viewed_at', null,
            ])
            ->one();
    }

    /**
     * @return ActiveQuery
     * @throws \yii\base\InvalidConfigException
     */
    public function getMatchesOrderedByUserRating(): ActiveQuery
    {
        return $this
            ->getMatches()
            ->joinWith('user')
            ->orderBy([
                'user.rating' => SORT_DESC,
                'user.created_at' => SORT_ASC,
            ]);
    }

    /**
     * @return \yii\db\ActiveQuery
     * @throws \yii\base\InvalidConfigException
     */
    public function getCounterMatches(): ActiveQuery
    {
        return $this->hasMany(self::class, ['id' => 'order_id'])
            ->viaTable('{{%currency_exchange_order_match}}', ['match_order_id' => 'id']);
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->sellingCurrency->code.' / '.$this->buyingCurrency->code;
    }

    /**
     * @return string
     */
    public function getInverseTitle()
    {
        return $this->buyingCurrency->code.' / '.$this->sellingCurrency->code;
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getSellingCurrency()
    {
        return $this->hasOne(Currency::class, ['id' => 'selling_currency_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getBuyingCurrency()
    {
        return $this->hasOne(Currency::class, ['id' => 'buying_currency_id']);
    }

    /**
     * @return bool
     */
    public function isActive()
    {
        return $this->status == self::STATUS_ON;
    }

    public function setActive(): self
    {
        $this->status = static::STATUS_ON;
        return $this;
    }

    public function setInactive(): self
    {
        $this->status = static::STATUS_OFF;
        return $this;
    }

    public function clearMatches()
    {
        (new ModelLinker($this))->clearMatches();
    }

    public function getSellingCurrencyMinAmount(): string
    {
        if ($this->selling_currency_min_amount) {
            return number_format($this->selling_currency_min_amount, 2);
        } else {
            return '∞';
        }
    }

    public function getSellingCurrencyMaxAmount(): string
    {
        if ($this->selling_currency_max_amount) {
            return number_format($this->selling_currency_max_amount, 2);
        } else {
            return '∞';
        }
    }

    public function getSellingCrossRate()
    {
        return CurrencyRate::find()->where(['from_currency_id' => $this->selling_currency_id, 'to_currency_id' => $this->buying_currency_id])->one();
    }

    public function getBuyingCrossRate()
    {
        return CurrencyRate::find()->where(['from_currency_id' => $this->buying_currency_id, 'to_currency_id' => $this->selling_currency_id])->one();
    }

    public function hasAmount(): bool
    {
        if ($this->selling_currency_min_amount || $this->selling_currency_max_amount) {
            return true;
        }

        return false;
    }

    public function getSellingPaymentMethodIds(): array
    {
        return ArrayHelper::getColumn($this->getSellingPaymentMethods()->asArray()->all(), 'id');
    }

    public function getBuyingPaymentMethodIds(): array
    {
        return ArrayHelper::getColumn($this->getBuyingPaymentMethods()->asArray()->all(), 'id');
    }

    public function getFormatLimits(): string
    {
        if (($this->selling_currency_min_amount) && ($this->selling_currency_max_amount)) {
            return number_format($this->selling_currency_min_amount, 2) . ' - ' . number_format($this->selling_currency_max_amount, 2) . ' ' . $this->sellingCurrency->code;
        }
        if ($this->selling_currency_min_amount) {
            return number_format($this->selling_currency_min_amount, 2) . ' - ' . '∞' . ' ' . $this->sellingCurrency->code;
        }
        if ($this->selling_currency_max_amount) {
            return  '∞' . ' - ' . number_format($this->selling_currency_max_amount, 2) . ' ' . $this->sellingCurrency->code;
        }

        return '∞';
    }

    public function getFeeBadge($direction = true)
    {
        if ($this->fee != 0) {
            $classes = [
                0 => 'success',
                1 => 'danger',
            ];

            if (!$direction) {
                $classes = array_reverse($classes);
            }

            $class = ($this->fee > 0 ? $classes[0] : ($this->fee < 0 ? $classes[1] : ''));

            return Html::badge($class, ($this->fee > 0 ? '+' : '') . (float)$this->fee . '%');
        }
    }

    public function beforeSave($insert)
    {
        if (!$insert && (new UpdateScenario($this))->run()) {
            $this->processed_at = null;
        }

        return parent::beforeSave($insert);
    }
}
