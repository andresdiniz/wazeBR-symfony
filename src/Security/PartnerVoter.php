<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Partner;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter para validar acesso baseado em partner conforme PRODUCT_RULES.md
 * 
 * Rules:
 * - ROLE_ADMIN (global/without partner): Can access all data across all partners
 * - ROLE_PARTNER_ADMIN: Can manage data within their partner only
 * - ROLE_OPERATOR: Can view/edit data within their partner only
 * - ROLE_VIEWER: Can view data within their partner only
 */
class PartnerVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';
    
    /**
     * Attributes that require partner validation
     */
    private const PARTNER_SCOPED_ATTRIBUTES = [
        self::VIEW,
        self::EDIT,
        self::DELETE,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Check if attribute is one of our partner-scoped actions
        if (!in_array($attribute, self::PARTNER_SCOPED_ATTRIBUTES, true)) {
            return false;
        }

        // Support Partner objects directly
        if ($subject instanceof Partner) {
            return true;
        }

        // Support objects that have a getPartner() method (e.g., Alert, Camera, etc.)
        if (is_object($subject) && method_exists($subject, 'getPartner')) {
            return true;
        }

        return false;
    }

    protected function voteOnToken(TokenInterface $token, string $attribute, mixed $subject): bool
    {
        $user = $token->getUser();

        // If user is not authenticated, deny access
        if (!$user instanceof User) {
            return false;
        }

        // ROLE_ADMIN without partner (global admin) can access everything
        if ($user->isGlobalAdmin()) {
            return true;
        }

        // Get the partner from the subject
        $subjectPartner = $this->getSubjectPartner($subject);

        // If subject has no partner, allow access (public data)
        if ($subjectPartner === null) {
            return true;
        }

        // For partner-scoped users, validate they belong to the same partner
        $userPartner = $user->getPartner();

        // User without partner cannot access partner-scoped data
        if ($userPartner === null) {
            return false;
        }

        // Check if user's partner matches the subject's partner
        return $userPartner->getId() === $subjectPartner->getId();
    }

    /**
     * Extract partner from subject (Partner object or object with getPartner())
     */
    private function getSubjectPartner(mixed $subject): ?Partner
    {
        if ($subject instanceof Partner) {
            return $subject;
        }

        if (is_object($subject) && method_exists($subject, 'getPartner')) {
            return $subject->getPartner();
        }

        return null;
    }
}
