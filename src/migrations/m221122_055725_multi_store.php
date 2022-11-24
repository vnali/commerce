<?php

namespace craft\commerce\migrations;

use craft\commerce\db\Table;
use craft\db\Migration;
use craft\db\Query;

/**
 * m221122_055725_multi_store migration.
 */
class m221122_055725_multi_store extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {

        // 1. Create the new stores settings table
        // 2. Copy the stores current marketAddressConditions, countries, and locationAddressId to the new table
        // 3. Remove the columns from the stores table

        if (!$this->db->tableExists(Table::STORESETTINGS)) {
            $this->createTable(Table::STORESETTINGS, [
                'id' => $this->integer()->notNull(),
                'locationAddressId' => $this->integer(),
                'countries' => $this->text(),
                'marketAddressCondition' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
                'PRIMARY KEY(id)',
            ]);

            $this->addForeignKey(null, Table::STORESETTINGS, ['id'], Table::STORES, ['id'], 'CASCADE', 'CASCADE');
        }

        // get store settings from db
        $storeSettings = (new Query())
            ->select(['id', 'locationAddressId', 'countries', 'marketAddressCondition'])
            ->from([Table::STORES])
            ->one();

        $this->insert(Table::STORESETTINGS, $storeSettings);

        $this->dropColumn(Table::STORES, 'locationAddressId');
        $this->dropColumn(Table::STORES, 'countries');
        $this->dropColumn(Table::STORES, 'marketAddressCondition');

        // if column doesnt exist
        if (!$this->db->columnExists(Table::STORES, 'name')) {
            $this->addColumn(Table::STORES, 'name', $this->string()->defaultValue('')->notNull());
        }
        if (!$this->db->columnExists(Table::STORES, 'handle')) {
            $this->addColumn(Table::STORES, 'handle', $this->string()->defaultValue('')->notNull());
        }
        if (!$this->db->columnExists(Table::STORES, 'primary')) {
            $this->addColumn(Table::STORES, 'primary', $this->boolean()->defaultValue(false)->notNull());
        }

        $this->update(table: Table::STORES,
            columns: ['name' => 'Primary Store', 'handle' => 'primary-store', 'primary' => true],
            condition: ['id' => $storeSettings['id']],
            updateTimestamp: false
        );


        // TODO: Add Project Config migration / creation of primary store in Project Config

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m221122_055725_multi_store cannot be reverted.\n";
        return false;
    }
}
