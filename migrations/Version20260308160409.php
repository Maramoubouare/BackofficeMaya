<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260308160409 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE transaction (id INT AUTO_INCREMENT NOT NULL, transaction_id VARCHAR(255) NOT NULL, amount DOUBLE PRECISION NOT NULL, payment_method VARCHAR(50) NOT NULL, status VARCHAR(20) NOT NULL, paydunya_token VARCHAR(255) DEFAULT NULL, phone_number VARCHAR(15) DEFAULT NULL, created_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, metadata JSON DEFAULT NULL, reservation_id INT NOT NULL, UNIQUE INDEX UNIQ_723705D12FC0CB0F (transaction_id), INDEX IDX_723705D1B83297E7 (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D1B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id)');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY `FK_EAA81A4CB83297E7`');
        $this->addSql('DROP TABLE transactions');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE transactions (id INT AUTO_INCREMENT NOT NULL, payment_method VARCHAR(50) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_general_ci`, status VARCHAR(20) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_general_ci`, phone_number VARCHAR(20) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_general_ci`, created_at DATETIME NOT NULL, reservation_id INT NOT NULL, transaction_id VARCHAR(255) CHARACTER SET utf8mb3 NOT NULL COLLATE `utf8mb3_general_ci`, amount DOUBLE PRECISION NOT NULL, cinetpay_transaction_id VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_general_ci`, cinetpay_payment_token VARCHAR(255) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_general_ci`, cinetpay_payment_url LONGTEXT CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_general_ci`, completed_at DATETIME DEFAULT NULL, metadata JSON DEFAULT NULL, operator VARCHAR(50) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_general_ci`, INDEX IDX_EAA81A4CB83297E7 (reservation_id), UNIQUE INDEX UNIQ_EAA81A4C2FC0CB0F (transaction_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT `FK_EAA81A4CB83297E7` FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transaction DROP FOREIGN KEY FK_723705D1B83297E7');
        $this->addSql('DROP TABLE transaction');
    }
}
