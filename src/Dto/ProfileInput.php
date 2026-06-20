<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ProfileInput
{
    #[Assert\Length(max: 300)]
    public ?string $bio = null;

    #[Assert\Length(max: 255)]
    public ?string $location = null;
}
