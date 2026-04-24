<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260111012443 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE reservations (id INT AUTO_INCREMENT NOT NULL, reservation_id VARCHAR(50) NOT NULL, passenger_first_name VARCHAR(100) NOT NULL, passenger_last_name VARCHAR(100) NOT NULL, passenger_phone VARCHAR(20) NOT NULL, passenger_email VARCHAR(255) DEFAULT NULL, num_passengers INT NOT NULL, num_adults INT NOT NULL, num_children INT NOT NULL, num_babies INT NOT NULL, travel_date DATE NOT NULL, total_price NUMERIC(10, 2) NOT NULL, commission_amount NUMERIC(10, 2) NOT NULL, company_amount NUMERIC(10, 2) NOT NULL, payment_method VARCHAR(50) NOT NULL, payment_status VARCHAR(20) NOT NULL, payment_phone VARCHAR(100) DEFAULT NULL, transaction_id VARCHAR(100) DEFAULT NULL, paid_at DATETIME DEFAULT NULL, pdf_url VARCHAR(255) DEFAULT NULL, qr_code VARCHAR(255) DEFAULT NULL, status VARCHAR(20) NOT NULL, cancellation_reason LONGTEXT DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, modification_logs JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, trip_id INT NOT NULL, company_id INT NOT NULL, cancelled_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_4DA239B83297E7 (reservation_id), INDEX IDX_4DA239A5BC2E0E (trip_id), INDEX IDX_4DA239979B1AD6 (company_id), INDEX IDX_4DA239187B2D12 (cancelled_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8');
        $this->addSql('CREATE TABLE support_tickets (id INT AUTO_INCREMENT NOT NULL, phone VARCHAR(20) NOT NULL, email VARCHAR(100) DEFAULT NULL, subject VARCHAR(255) NOT NULL, category VARCHAR(100) NOT NULL, message LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, priority VARCHAR(20) NOT NULL, is_read TINYINT NOT NULL, unread_messages INT NOT NULL, resolved_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, reservation_id INT DEFAULT NULL, assigned_to_id INT DEFAULT NULL, INDEX IDX_E9739508B83297E7 (reservation_id), INDEX IDX_E9739508F4BD7827 (assigned_to_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8');
        $this->addSql('CREATE TABLE ticket_messages (id INT AUTO_INCREMENT NOT NULL, message LONGTEXT NOT NULL, is_agent TINYINT NOT NULL, is_read TINYINT NOT NULL, created_at DATETIME NOT NULL, ticket_id INT NOT NULL, agent_id INT DEFAULT NULL, INDEX IDX_5E6BE217700047D2 (ticket_id), INDEX IDX_5E6BE2173414710B (agent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8');
        $this->addSql('CREATE TABLE transactions (id INT AUTO_INCREMENT NOT NULL, total_amount NUMERIC(10, 2) NOT NULL, commission_amount NUMERIC(10, 2) NOT NULL, company_amount NUMERIC(10, 2) NOT NULL, commission_rate NUMERIC(5, 2) NOT NULL, commission_min NUMERIC(10, 2) NOT NULL, payment_method VARCHAR(50) NOT NULL, payment_status VARCHAR(20) NOT NULL, payment_phone VARCHAR(20) DEFAULT NULL, payment_transaction_id VARCHAR(100) DEFAULT NULL, payment_response LONGTEXT DEFAULT NULL, paid_at DATETIME DEFAULT NULL, paid_to_company TINYINT NOT NULL, paid_to_company_at DATETIME DEFAULT NULL, company_payment_reference VARCHAR(100) DEFAULT NULL, refund_amount NUMERIC(10, 2) DEFAULT NULL, refunded_at DATETIME DEFAULT NULL, refund_transaction_id VARCHAR(100) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, reservation_id INT NOT NULL, company_id INT NOT NULL, UNIQUE INDEX UNIQ_EAA81A4CB83297E7 (reservation_id), INDEX IDX_EAA81A4C979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8');
        $this->addSql('CREATE TABLE trips (id INT AUTO_INCREMENT NOT NULL, departure_city VARCHAR(100) NOT NULL, arrival_city VARCHAR(100) NOT NULL, departure_time TIME NOT NULL, arrival_time TIME NOT NULL, duration VARCHAR(20) DEFAULT NULL, price NUMERIC(10, 2) NOT NULL, total_seats INT NOT NULL, available_seats INT NOT NULL, days_of_week JSON NOT NULL, vehicle_type VARCHAR(50) DEFAULT NULL, has_ac TINYINT NOT NULL, has_break TINYINT NOT NULL, break_location VARCHAR(255) DEFAULT NULL, is_active TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, company_id INT NOT NULL, INDEX IDX_AA7370DA979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, phone VARCHAR(20) DEFAULT NULL, company_name VARCHAR(255) DEFAULT NULL, company_logo VARCHAR(255) DEFAULT NULL, commission_rate NUMERIC(5, 2) DEFAULT NULL, commission_min NUMERIC(10, 2) DEFAULT NULL, is_active TINYINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8');
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT FK_4DA239A5BC2E0E FOREIGN KEY (trip_id) REFERENCES trips (id)');
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT FK_4DA239979B1AD6 FOREIGN KEY (company_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT FK_4DA239187B2D12 FOREIGN KEY (cancelled_by_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE support_tickets ADD CONSTRAINT FK_E9739508B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id)');
        $this->addSql('ALTER TABLE support_tickets ADD CONSTRAINT FK_E9739508F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE ticket_messages ADD CONSTRAINT FK_5E6BE217700047D2 FOREIGN KEY (ticket_id) REFERENCES support_tickets (id)');
        $this->addSql('ALTER TABLE ticket_messages ADD CONSTRAINT FK_5E6BE2173414710B FOREIGN KEY (agent_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4CB83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id)');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4C979B1AD6 FOREIGN KEY (company_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE trips ADD CONSTRAINT FK_AA7370DA979B1AD6 FOREIGN KEY (company_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservations DROP FOREIGN KEY FK_4DA239A5BC2E0E');
        $this->addSql('ALTER TABLE reservations DROP FOREIGN KEY FK_4DA239979B1AD6');
        $this->addSql('ALTER TABLE reservations DROP FOREIGN KEY FK_4DA239187B2D12');
        $this->addSql('ALTER TABLE support_tickets DROP FOREIGN KEY FK_E9739508B83297E7');
        $this->addSql('ALTER TABLE support_tickets DROP FOREIGN KEY FK_E9739508F4BD7827');
        $this->addSql('ALTER TABLE ticket_messages DROP FOREIGN KEY FK_5E6BE217700047D2');
        $this->addSql('ALTER TABLE ticket_messages DROP FOREIGN KEY FK_5E6BE2173414710B');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_EAA81A4CB83297E7');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_EAA81A4C979B1AD6');
        $this->addSql('ALTER TABLE trips DROP FOREIGN KEY FK_AA7370DA979B1AD6');
        $this->addSql('DROP TABLE reservations');
        $this->addSql('DROP TABLE support_tickets');
        $this->addSql('DROP TABLE ticket_messages');
        $this->addSql('DROP TABLE transactions');
        $this->addSql('DROP TABLE trips');
        $this->addSql('DROP TABLE users');
    }
}
