<?php

namespace App\Dto;

use App\Category\CategoryPalette;
use Symfony\Component\Validator\Constraints as Assert;

class CategoryInput
{
    #[Assert\NotBlank(message: 'Category name is required.')]
    #[Assert\Length(max: 255, maxMessage: 'Category name is too long.')]
    public string $name = '';

    #[Assert\NotBlank(message: 'A colour is required.')]
    #[Assert\Choice(callback: [CategoryPalette::class, 'colors'], message: 'Unsupported colour.')]
    public string $colorHex = '';
}
