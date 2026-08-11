<?php

namespace App\Dto;

use App\Analytics\AnalyticsRoutes;
use Symfony\Component\Validator\Constraints as Assert;

class PageViewInput
{
    /**
     * The SPA route name, checked against a fixed server-side allow-list — see
     * AnalyticsRoutes for why that bound is the whole security model of this
     * endpoint.
     */
    #[Assert\NotBlank(message: 'A route name is required.')]
    #[Assert\Choice(callback: [AnalyticsRoutes::class, 'names'], message: 'Unknown route.')]
    public string $route = '';
}
