<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Partner;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class PartnerVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';
    
    private const PARTNER_SCOPED_ATTRIBUTES = [
        self::VIEW,
        self::EDIT,
        self::DELETE,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, self::PARTNER_SCOPED_ATTRIBUTES, true)) {
            return false;
        }

        if ($subject instanceof Partner) {
            return true;
        }

        if (is_object($subject) && method_exists($subject, 'getPartner')) {
            return true;
        }

        return false;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if ($user->isGlobalAdmin()) {
            return true;
        }

        $subjectPartner = $this->getSubjectPartner($subject);

        if ($subjectPartner === null) {
            return true;
        }

        $userPartner = $user->getPartner();

        if ($userPartner === null) {
            return false;
        }

        return $userPartner->getId() === $subjectPartner->getId();
    }

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
