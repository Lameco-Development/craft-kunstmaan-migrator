<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'legacy_user_story_items')]
#[ORM\Entity]
class UserStoryItem
{
    #[ORM\JoinColumn(name: 'block_link_pp_id', referencedColumnName: 'id')]
    #[ORM\ManyToOne(targetEntity: UserStoriesPagePart::class, inversedBy: 'items')]
    private $userStoriesPagePart;
}
