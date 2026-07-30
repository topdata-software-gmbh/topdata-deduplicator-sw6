<?php declare(strict_types=1);

namespace Topdata\TopdataDeduplicatorSW6\Controller;

use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[RouteScope(scopes: ['storefront'])]
class StorefrontExampleController extends StorefrontController
{
    #[Route(
        path: '/deduplicatorsw6/example', 
        name: 'frontend.deduplicatorsw6.example', 
        methods: ['GET']
    )]
    public function exampleAction(): Response
    {
        return $this->renderStorefront('@TopdataDeduplicatorSW6/storefront/example.html.twig', [
            'pluginName' => 'DeduplicatorSW6'
        ]);
    }
}