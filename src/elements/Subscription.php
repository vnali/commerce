<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\elements;

use Craft;
use craft\base\Element;
use craft\commerce\base\Plan;
use craft\commerce\base\PlanInterface;
use craft\commerce\base\SubscriptionGatewayInterface;
use craft\commerce\db\Table;
use craft\commerce\elements\db\SubscriptionQuery;
use craft\commerce\models\subscriptions\SubscriptionPayment;
use craft\commerce\Plugin;
use craft\commerce\records\Subscription as SubscriptionRecord;
use craft\db\Query;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use DateInterval;
use DateTime;
use Exception;
use yii\base\InvalidConfigException;

/**
 * Subscription model.
 *
 * @property bool $isOnTrial whether the subscription is still on trial
 * @property string $nextPaymentAmount
 * @property SubscriptionGatewayInterface $gateway
 * @property PlanInterface $plan
 * @property string $name
 * @property Plan[] $alternativePlans
 * @property string $orderEditUrl
 * @property string $planName
 * @property SubscriptionPayment[] $allPayments
 * @property User $subscriber
 * @property string $eagerLoadedElements
 * @property DateTime $trialExpires datetime of trial expiry
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2015, Pixel & Tonic, Inc.
 * @since 2.0
 */
class Subscription extends Element
{
    /**
     * @var string
     */
    const STATUS_ACTIVE = 'active';

    /**
     * @var string
     */
    const STATUS_EXPIRED = 'expired';

    /**
     * @var string
     */
    const STATUS_SUSPENDED = 'suspended';


    /**
     * @var int User id
     */
    public $userId;

    /**
     * @var int Plan id
     */
    public $planId;

    /**
     * @var int Gateway id
     */
    public $gatewayId;

    /**
     * @var int|null Order id
     */
    public $orderId;

    /**
     * @var string Subscription reference on the gateway
     */
    public $reference;

    /**
     * @var int Trial days granted
     */
    public $trialDays;

    /**
     * @var DateTime Date of next payment
     */
    public $nextPaymentDate;

    /**
     * @var bool Whether the subscription is canceled
     */
    public $isCanceled;

    /**
     * @var DateTime Time when subscription was canceled
     */
    public $dateCanceled;

    /**
     * @var bool Whether the subscription has expired
     */
    public $isExpired;

    /**
     * @var DateTime Time when subscription expired
     */
    public $dateExpired;

    /**
     * @var bool Whether the subscription has started
     */
    public $hasStarted;

    /**
     * @var bool Whether the subscription is on hold due to payment issues
     */
    public $isSuspended;

    /**
     * @var DateTime Time when subscription was put on hold
     */
    public $dateSuspended;

    /**
     * @var SubscriptionGatewayInterface
     */
    private $_gateway;

    /**
     * @var Plan
     */
    private $_plan;

    /**
     * @var User
     */
    private $_user;

    /**
     * @var Order
     */
    private $_order;

    /**
     * @var array The subscription data from gateway
     */
    public $_subscriptionData;


    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'Subscription');
    }

    /**
     * @inheritdoc
     */
    public static function lowerDisplayName(): string
    {
        return Craft::t('commerce', 'subscription');
    }

    /**
     * @inheritdoc
     */
    public static function pluralDisplayName(): string
    {
        return Craft::t('commerce', 'Subscriptions');
    }

    /**
     * @inheritdoc
     */
    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('commerce', 'subscriptions');
    }

    /**
     * @return null|string
     */
    public function __toString()
    {
        return Craft::t('commerce', 'Subscription to “{plan}”', ['plan' => (string)$this->getPlan()]);
    }

    /**
     * Returns whether this subscription can be reactivated.
     *
     * @return bool
     * @throws InvalidConfigException if gateway misconfigured
     */
    public function canReactivate()
    {
        return $this->isCanceled && !$this->isExpired && $this->getGateway()->supportsReactivation();
    }

    /**
     * @inheritdoc
     */
    public function getFieldLayout()
    {
        return Craft::$app->getFields()->getLayoutByType(static::class);
    }

    /**
     * Returns whether this subscription is on trial.
     *
     * @return bool
     */
    public function getIsOnTrial()
    {
        if ($this->isExpired) {
            return false;
        }

        return $this->trialDays > 0 && time() <= $this->getTrialExpires()->getTimestamp();
    }

    /**
     * Returns the subscription plan for this subscription
     *
     * @return PlanInterface
     */
    public function getPlan(): PlanInterface
    {
        if (null === $this->_plan) {
            $this->_plan = Plugin::getInstance()->getPlans()->getPlanById($this->planId);
        }

        return $this->_plan;
    }

    /**
     * Returns the User that is subscribed.
     *
     * @return User
     */
    public function getSubscriber(): User
    {
        if (null === $this->_user) {
            $this->_user = Craft::$app->getUsers()->getUserById($this->userId);
        }

        return $this->_user;
    }

    /**
     * @return array
     */
    public function getSubscriptionData(): array
    {
        return $this->_subscriptionData;
    }

    /**
     *
     * @param string|array $data
     */
    public function setSubscriptionData($data)
    {
        $data = Json::decodeIfJson($data);

        $this->_subscriptionData = $data;
    }

    /**
     * Returns the datetime of trial expiry.
     *
     * @return DateTime
     * @throws Exception
     */
    public function getTrialExpires(): DateTIme
    {
        $created = clone $this->dateCreated;
        return $created->add(new DateInterval('P' . $this->trialDays . 'D'));
    }

    /**
     * Returns the next payment amount with currency code as a string.
     *
     * @return string
     * @throws InvalidConfigException
     */
    public function getNextPaymentAmount(): string
    {
        return $this->getGateway()->getNextPaymentAmount($this);
    }

    /**
     * Returns the order that included this subscription, if any.
     *
     * @return null|Order
     */
    public function getOrder()
    {
        if ($this->_order) {
            return $this->_order;
        }

        if ($this->orderId) {
            return $this->_order = Plugin::getInstance()->getOrders()->getOrderById($this->orderId);
        }

        return null;
    }

    /**
     * Returns the product type for the product tied to the license.
     *
     * @return SubscriptionGatewayInterface
     * @throws InvalidConfigException if gateway misconfigured
     */
    public function getGateway(): SubscriptionGatewayInterface
    {
        if (null === $this->_gateway) {
            $this->_gateway = Plugin::getInstance()->getGateways()->getGatewayById($this->gatewayId);
            if (!$this->_gateway instanceof SubscriptionGatewayInterface) {
                throw new InvalidConfigException('The gateway set for subscription does not support subscriptions.');
            }
        }

        return $this->_gateway;
    }

    /**
     * @return string
     */
    public function getPlanName(): string
    {
        return (string)$this->getPlan();
    }

    /**
     * Returns possible alternative plans for this subscription
     *
     * @return Plan[]
     */
    public function getAlternativePlans(): array
    {
        $plans = Plugin::getInstance()->getPlans()->getAllGatewayPlans($this->gatewayId);

        /** @var Plan $currentPlan */
        $currentPlan = $this->getPlan();

        $alternativePlans = [];

        foreach ($plans as $plan) {
            // For all plans that are not the current plan
            if ($plan->id !== $currentPlan->id && $plan->canSwitchFrom($currentPlan)) {
                $alternativePlans[] = $plan;
            }
        }

        return $alternativePlans;
    }

    /**
     * @inheritdoc
     */
    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('commerce/subscriptions/' . $this->id);
    }

    /**
     * Returns the link for editing the order that purchased this license.
     *
     * @return string
     */
    public function getOrderEditUrl(): string
    {
        if ($this->orderId) {
            return UrlHelper::cpUrl('commerce/orders/' . $this->orderId);
        }

        return '';
    }

    /**
     * Returns an array of all payments for this subscription.
     *
     * @return SubscriptionPayment[]
     * @throws InvalidConfigException
     */
    public function getAllPayments(): array
    {
        return $this->getGateway()->getSubscriptionPayments($this);
    }

    /**
     * @return null|string
     */
    public function getName()
    {
        return Craft::t('commerce', 'Subscription to “{plan}”', ['plan' => $this->getPlanName()]);
    }

    /**
     * @inheritdoc
     */
    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getStatus()
    {
        if ($this->isExpired) {
            return self::STATUS_EXPIRED;
        }

        return $this->isSuspended ? self::STATUS_SUSPENDED : self::STATUS_ACTIVE;
    }


    /**
     * @inheritdoc
     */
    public static function defineSources(string $context = null): array
    {
        $plans = Plugin::getInstance()->getPlans()->getAllPlans();

        $planIds = [];

        foreach ($plans as $plan) {
            $planIds[] = $plan->id;
        }


        $sources = [
            '*' => [
                'key' => '*',
                'label' => Craft::t('commerce', 'All active subscriptions'),
                'criteria' => ['planId' => $planIds],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
        ];

        $sources[] = ['heading' => Craft::t('commerce', 'Subscription plans')];

        foreach ($plans as $plan) {
            $key = 'plan:' . $plan->id;

            $sources[$key] = [
                'key' => $key,
                'label' => $plan->name,
                'data' => [
                    'handle' => $plan->handle,
                ],
                'criteria' => ['planId' => $plan->id],
            ];
        }

        $sources[] = ['heading' => Craft::t('commerce', 'Subscriptions on hold')];

        $criteriaFailedToStart = ['isSuspended' => true, 'hasStarted' => false];
        $sources[] = [
            'key' => 'carts:failed-to-start',
            'label' => Craft::t('commerce', 'Failed to start'),
            'criteria' => $criteriaFailedToStart,
            'defaultSort' => ['commerce_subscriptions.dateUpdated', 'desc'],
        ];

        $criteriaPaymentIssue = ['isSuspended' => true, 'hasStarted' => true];
        $sources[] = [
            'key' => 'carts:payment-issue',
            'label' => Craft::t('commerce', 'Payment method issue'),
            'criteria' => $criteriaPaymentIssue,
            'defaultSort' => ['commerce_subscriptions.dateUpdated', 'desc'],
        ];

        return $sources;
    }

    /**
     * @inheritdoc
     */
    public static function hasContent(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function eagerLoadingMap(array $sourceElements, string $handle)
    {
        $sourceElementIds = ArrayHelper::getColumn($sourceElements, 'id');

        if ($handle === 'subscriber') {
            $map = (new Query())
                ->select('id as source, userId as target')
                ->from(Table::SUBSCRIPTIONS)
                ->where(['in', 'id', $sourceElementIds])
                ->all();

            return [
                'elementType' => User::class,
                'map' => $map,
            ];
        }

        return parent::eagerLoadingMap($sourceElements, $handle);
    }

    /**
     * @inheritdoc
     */
    public function setEagerLoadedElements(string $handle, array $elements)
    {
        if ($handle === 'order') {
            $this->_order = $elements[0] ?? null;

            return;
        }

        if ($handle === 'subscriber') {
            $this->_user = $elements[0] ?? null;

            return;
        }

        parent::setEagerLoadedElements($handle, $elements);
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['userId', 'planId', 'gatewayId', 'reference', 'subscriptionData'], 'required'],
        ]);
    }

    /**
     * @inheritdocs
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE => Craft::t('commerce', 'Active'),
            self::STATUS_EXPIRED => Craft::t('commerce', 'Expired'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        $attributes = parent::datetimeAttributes();
        $attributes[] = 'nextPaymentDate';
        $attributes[] = 'dateExpired';
        $attributes[] = 'dateCanceled';
        $attributes[] = 'dateSuspended';
        return $attributes;
    }

    /**
     * @inheritdoc
     * @return SubscriptionQuery The newly created [[SubscriptionQuery]] instance.
     */
    public static function find(): ElementQueryInterface
    {
        return new SubscriptionQuery(static::class);
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew)
    {
        if (!$isNew) {
            $subscriptionRecord = SubscriptionRecord::findOne($this->id);

            if (!$subscriptionRecord) {
                throw new InvalidConfigException('Invalid subscription id: ' . $this->id);
            }
        } else {
            $subscriptionRecord = new SubscriptionRecord();
            $subscriptionRecord->id = $this->id;
        }

        $subscriptionRecord->planId = $this->planId;
        $subscriptionRecord->nextPaymentDate = $this->nextPaymentDate;
        $subscriptionRecord->subscriptionData = $this->subscriptionData;
        $subscriptionRecord->isCanceled = $this->isCanceled;
        $subscriptionRecord->dateCanceled = $this->dateCanceled;
        $subscriptionRecord->isExpired = $this->isExpired;
        $subscriptionRecord->dateExpired = $this->dateExpired;
        $subscriptionRecord->hasStarted = $this->hasStarted;
        $subscriptionRecord->isSuspended = $this->isSuspended;
        $subscriptionRecord->dateSuspended = $this->dateSuspended;

        // We want to always have the same date as the element table, based on the logic for updating these in the element service i.e resaving
        $subscriptionRecord->dateUpdated = $this->dateUpdated;
        $subscriptionRecord->dateCreated = $this->dateCreated;

        // Some properties of the subscription are immutable
        if ($isNew) {
            $subscriptionRecord->gatewayId = $this->gatewayId;
            $subscriptionRecord->orderId = $this->orderId;
            $subscriptionRecord->reference = $this->reference;
            $subscriptionRecord->trialDays = $this->trialDays;
            $subscriptionRecord->userId = $this->userId;
        }

        $subscriptionRecord->save(false);

        parent::afterSave($isNew);
    }

    /**
     * Return a description of the billing issue (if any) with this subscription.
     *
     * @return mixed
     * @throws InvalidConfigException if not a subscription gateway anymore
     */
    public function getBillingIssueDescription()
    {
        return $this->getGateway()->getBillingIssueDescription($this);
    }

    /**
     * Return the form HTML for resolving the billing issue (if any) with this subscription.
     *
     * @return mixed
     * @throws InvalidConfigException if not a subscription gateway anymore
     */
    public function getBillingIssueResolveFormHtml()
    {
        return $this->getGateway()->getBillingIssueResolveFormHtml($this);
    }

    /**
     * Return whether this subscription has billing issues.
     *
     * @return mixed
     * @throws InvalidConfigException if not a subscription gateway anymore
     */
    public function getHasBillingIssues()
    {
        return $this->getGateway()->getHasBillingIssues($this);
    }

    /**
     * @inheritdoc
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('commerce', 'Subscription plan')],
            'subscriber' => ['label' => Craft::t('commerce', 'Subscribing user')],
            'reference' => ['label' => Craft::t('commerce', 'Subscription reference')],
            'dateCanceled' => ['label' => Craft::t('commerce', 'Cancellation date')],
            'dateCreated' => ['label' => Craft::t('commerce', 'Subscription date')],
            'dateExpired' => ['label' => Craft::t('commerce', 'Expiry date')],
            'trialExpires' => ['label' => Craft::t('commerce', 'Trial expiry date')],
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        $attributes = [];

        $attributes[] = 'subscriber';
        $attributes[] = 'orderLink';
        $attributes[] = 'dateCreated';

        return $attributes;
    }

    /**
     * @inheritdoc
     */
    protected static function defineSearchableAttributes(): array
    {
        return [
            'subscriber',
            'plan',
        ];
    }

    /**
     * @inheritdoc
     */
    protected function tableAttributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'plan':
                return $this->getPlanName();

            case 'subscriber':
                $subscriber = $this->getSubscriber();
                $url = $subscriber->getCpEditUrl();

                return '<a href="' . $url . '">' . $subscriber . '</a>';

            case 'orderLink':
                $url = $this->getOrderEditUrl();

                return $url ? '<a href="' . $url . '">' . Craft::t('commerce', 'View order') . '</a>' : '';

            default:
            {
                return parent::tableAttributeHtml($attribute);
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected static function defineSortOptions(): array
    {
        return [
            [
                'label' => Craft::t('commerce', 'Subscription date'),
                'orderBy' => 'commerce_subscriptions.dateCreated',
                'attribute' => 'dateCreated',
                'defaultDir' => 'desc',
            ],
            [
                'label' => Craft::t('app', 'ID'),
                'orderBy' => 'elements.id',
                'attribute' => 'id',
            ],
        ];
    }


    /**
     * @inheritdoc
     */
    protected static function prepElementQueryForTableAttribute(ElementQueryInterface $elementQuery, string $attribute)
    {
        switch ($attribute) {
            case 'subscriber':
                $elementQuery->andWith('subscriber');
                break;
            case 'orderLink':
                $elementQuery->andWith('order');
                break;
            default:
                parent::prepElementQueryForTableAttribute($elementQuery, $attribute);
        }
    }
}
