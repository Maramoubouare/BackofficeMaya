<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260308152758 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_key ON settings');
        $this->addSql('ALTER TABLE settings CHANGE setting_value setting_value LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE settings RENAME INDEX setting_key TO UNIQ_E545A0C55FA1E697');
        $this->addSql('ALTER TABLE transactions DROP INDEX UNIQ_EAA81A4CB83297E7, ADD INDEX IDX_EAA81A4CB83297E7 (reservation_id)');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY `FK_EAA81A4CB83297E7`');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY `FK_EAA81A4C979B1AD6`');
        $this->addSql('DROP INDEX IDX_EAA81A4C979B1AD6 ON transactions');
        $this->addSql('ALTER TABLE transactions ADD transaction_id VARCHAR(255) NOT NULL, ADD amount DOUBLE PRECISION NOT NULL, ADD cinetpay_transaction_id VARCHAR(255) DEFAULT NULL, ADD cinetpay_payment_token VARCHAR(255) DEFAULT NULL, ADD cinetpay_payment_url LONGTEXT DEFAULT NULL, ADD completed_at DATETIME DEFAULT NULL, ADD metadata JSON DEFAULT NULL, ADD operator VARCHAR(50) DEFAULT NULL, DROP total_amount, DROP commission_amount, DROP company_amount, DROP commission_rate, DROP commission_min, DROP payment_transaction_id, DROP payment_response, DROP paid_at, DROP paid_to_company, DROP paid_to_company_at, DROP company_payment_reference, DROP refund_amount, DROP refunded_at, DROP refund_transaction_id, DROP notes, DROP updated_at, DROP company_id, CHANGE payment_status status VARCHAR(20) NOT NULL, CHANGE payment_phone phone_number VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4CB83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EAA81A4C2FC0CB0F ON transactions (transaction_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE settings CHANGE setting_value setting_value TEXT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_key ON settings (setting_key)');
        $this->addSql('ALTER TABLE settings RENAME INDEX uniq_e545a0c55fa1e697 TO setting_key');
        $this->addSql('ALTER TABLE transactions DROP INDEX IDX_EAA81A4CB83297E7, ADD UNIQUE INDEX UNIQ_EAA81A4CB83297E7 (reservation_id)');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_EAA81A4CB83297E7');
        $this->addSql('DROP INDEX UNIQ_EAA81A4C2FC0CB0F ON transactions');
        $this->addSql('ALTER TABLE transactions ADD total_amount NUMERIC(10, 2) NOT NULL, ADD commission_amount NUMERIC(10, 2) NOT NULL, ADD company_amount NUMERIC(10, 2) NOT NULL, ADD commission_rate NUMERIC(5, 2) NOT NULL, ADD commission_min NUMERIC(10, 2) NOT NULL, ADD payment_transaction_id VARCHAR(100) DEFAULT NULL, ADD paid_to_company TINYINT NOT NULL, ADD paid_to_company_at DATETIME DEFAULT NULL, ADD company_payment_reference VARCHAR(100) DEFAULT NULL, ADD refund_amount NUMERIC(10, 2) DEFAULT NULL, ADD refunded_at DATETIME DEFAULT NULL, ADD refund_transaction_id VARCHAR(100) DEFAULT NULL, ADD notes LONGTEXT DEFAULT NULL, ADD updated_at DATETIME NOT NULL, ADD company_id INT NOT NULL, DROP transaction_id, DROP amount, DROP cinetpay_transaction_id, DROP cinetpay_payment_token, DROP metadata, DROP operator, CHANGE status payment_status VARCHAR(20) NOT NULL, CHANGE phone_number payment_phone VARCHAR(20) DEFAULT NULL, CHANGE cinetpay_payment_url payment_response LONGTEXT DEFAULT NULL, CHANGE completed_at paid_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT `FK_EAA81A4CB83297E7` FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT `FK_EAA81A4C979B1AD6` FOREIGN KEY (company_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_EAA81A4C979B1AD6 ON transactions (company_id)');
    }
}
