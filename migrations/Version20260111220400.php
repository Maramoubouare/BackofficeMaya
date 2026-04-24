<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260111220400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE trip_availability (id INT AUTO_INCREMENT NOT NULL, travel_date DATE NOT NULL, available_seats INT NOT NULL, total_seats INT NOT NULL, reserved_seats INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, trip_id INT NOT NULL, INDEX IDX_322EE926A5BC2E0E (trip_id), INDEX idx_trip_date (trip_id, travel_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8');
        $this->addSql('ALTER TABLE trip_availability ADD CONSTRAINT FK_322EE926A5BC2E0E FOREIGN KEY (trip_id) REFERENCES trips (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE trip_availability DROP FOREIGN KEY FK_322EE926A5BC2E0E');
        $this->addSql('DROP TABLE trip_availability');
    }
}
