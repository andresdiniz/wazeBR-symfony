<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma regra "quero receber e-mail deste tipo/subtipo".
 * subtype = '*' significa "todos os subtipos do tipo".
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_notification_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_user_notification_subscription', columns: ['preference_id', 'category', 'type', 'subtype'])]
#[ORM\Index(columns: ['category', 'type'], name: 'idx_user_notification_subscription_cat_type')]
class UserNotificationSubscription
{
    public const CATEGORY_ALERT = 'ALERT';
    public const CATEGORY_EVENT = 'EVENT';
    public const WILDCARD = '*';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'subscriptions')]
    #[ORM\JoinColumn(name: 'preference_id', nullable: false, onDelete: 'CASCADE')]
    private UserNotificationPreference $preference;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $category;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $type;

    #[ORM\Column(type: Types::STRING, length: 100, options: ['default' => '*'])]
    private string $subtype;

    public function __construct(UserNotificationPreference $preference, string $category, string $type, string $subtype = self::WILDCARD)
    {
        $this->preference = $preference;
        $this->category = $category;
        $this->type = strtoupper($type);
        $this->subtype = $subtype === self::WILDCARD ? self::WILDCARD : strtoupper($subtype);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPreference(): UserNotificationPreference
    {
        return $this->preference;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSubtype(): string
    {
        return $this->subtype;
    }
}
