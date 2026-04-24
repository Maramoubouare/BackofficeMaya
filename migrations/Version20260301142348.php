<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260301142348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservations DROP FOREIGN KEY `FK_4DA239187B2D12`');
        $this->addSql('DROP INDEX IDX_4DA239187B2D12 ON reservations');
        $this->addSql('DROP INDEX UNIQ_4DA239B83297E7 ON reservations');
        $this->addSql('ALTER TABLE reservations DROP payment_method, DROP payment_status, DROP payment_phone, DROP transaction_id, DROP paid_at, DROP pdf_url, DROP qr_code, DROP cancellation_reason, DROP modification_logs, DROP updated_at, DROP cancelled_by_id, CHANGE company_amount company_amount NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE trip_availability DROP FOREIGN KEY `trip_availability_ibfk_1`');
        $this->addSql('DROP INDEX idx_travel_date ON trip_availability');
        $this->addSql('DROP INDEX unique_trips_date ON trip_availability');
        $this->addSql('ALTER TABLE trip_availability CHANGE total_seats total_seats INT NOT NULL, CHANGE reserved_seats reserved_seats INT NOT NULL, CHANGE available_seats available_seats INT NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE trip_availability ADD CONSTRAINT FK_322EE926A5BC2E0E FOREIGN KEY (trip_id) REFERENCES trips (id)');
        $this->addSql('ALTER TABLE trip_availability RENAME INDEX idx_trips_date TO idx_trip_date');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservations ADD payment_method VARCHAR(50) NOT NULL, ADD payment_status VARCHAR(20) NOT NULL, ADD payment_phone VARCHAR(100) DEFAULT NULL, ADD transaction_id VARCHAR(100) DEFAULT NULL, ADD paid_at DATETIME DEFAULT NULL, ADD pdf_url VARCHAR(255) DEFAULT NULL, ADD qr_code VARCHAR(255) DEFAULT NULL, ADD cancellation_reason LONGTEXT DEFAULT NULL, ADD modification_logs JSON NOT NULL, ADD updated_at DATETIME NOT NULL, ADD cancelled_by_id INT DEFAULT NULL, CHANGE company_amount company_amount NUMERIC(10, 2) NOT NULL');
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT `FK_4DA239187B2D12` FOREIGN KEY (cancelled_by_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_4DA239187B2D12 ON reservations (cancelled_by_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4DA239B83297E7 ON reservations (reservation_id)');
        $this->addSql('ALTER TABLE trip_availability DROP FOREIGN KEY FK_322EE926A5BC2E0E');
        $this->addSql('ALTER TABLE trip_availability CHANGE available_seats available_seats INT DEFAULT 50, CHANGE total_seats total_seats INT DEFAULT 50, CHANGE reserved_seats reserved_seats INT DEFAULT 0, CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP, CHANGE updated_at updated_at DATETIME DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('ALTER TABLE trip_availability ADD CONSTRAINT `trip_availability_ibfk_1` FOREIGN KEY (trip_id) REFERENCES trips (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_travel_date ON trip_availability (travel_date)');
        $this->addSql('CREATE UNIQUE INDEX unique_trips_date ON trip_availability (trip_id, travel_date)');
        $this->addSql('ALTER TABLE trip_availability RENAME INDEX idx_trip_date TO idx_trips_date');
    }
}
