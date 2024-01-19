<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\elements\conditions\orders;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\fields\conditions\MoneyFieldConditionRule;
use craft\fields\Money;
use yii\db\QueryInterface;

/**
 * Order Number Attribute Condition Rule
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.2.0
 *
 * @property-read float|int $orderAttributeValue
 */
abstract class OrderCurrencyValuesAttributeConditionRule extends MoneyFieldConditionRule
{
    public function __construct($config = [])
    {
        $this->setFieldUid('not-applicable');
        parent::__construct($config);
    }

    protected function field(): FieldInterface
    {
        $field = new Money();
        $field->currency = 'USD';

        return $field;
    }

    public function getCondition(): \craft\elements\conditions\ElementConditionInterface
    {
        return parent::getCondition();
    }

    public string $orderAttribute = '';

    /**
     * @inheritdoc
     */
    public function getExclusiveQueryParams(): array
    {
        return [$this->orderAttribute];
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Label not implemented';
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        return $this->matchValue($element->{$this->orderAttribute});
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(QueryInterface $query): void
    {
        $query->{$this->orderAttribute}($this->paramValue());
    }
}
