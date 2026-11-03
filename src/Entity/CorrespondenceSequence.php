<?php

namespace App\Entity;

use App\Repository\CorrespondenceSequenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Atomic sequence counter per year for correspondence reference generation.
 * Ensures MCI-YYYY-NNNNNN (incoming) and MCI-OUT-YYYY-NNNNNN (outgoing) references
 * are unique across concurrent transactions.
 */
#[ORM\Entity(repositoryClass: CorrespondenceSequenceRepository::class)]
#[ORM\Table(name: 'correspondence_sequences')]
class CorrespondenceSequence
{
    #[ORM\Id]
    #[ORM\Column]
    private int $year;

    #[ORM\Column(options: ['default' => 0])]
    private int $lastValue = 0;

    #[ORM\Column(name: 'last_outgoing_value', options: ['default' => 0])]
    private int $lastOutgoingValue = 0;

    public function __construct(int $year)
    {
        $this->year = $year;
    }

    public function getYear(): int { return $this->year; }
    public function getLastValue(): int { return $this->lastValue; }
    public function getLastOutgoingValue(): int { return $this->lastOutgoingValue; }

    /**
     * Atomically increments the incoming counter and returns the new value.
     */
    public function nextValue(): int
    {
        $this->lastValue++;
        return $this->lastValue;
    }

    /**
     * Atomically increments the outgoing counter and returns the new value.
     */
    public function nextOutgoingValue(): int
    {
        $this->lastOutgoingValue++;
        return $this->lastOutgoingValue;
    }
}
