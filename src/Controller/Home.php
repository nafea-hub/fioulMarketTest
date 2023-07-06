<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;

use App\Service\ImageService;
use Symfony\Contracts\Cache\ItemInterface;

class Home extends AbstractController
{
    private $imageService;
    private $cache;

    public function __construct(ImageService $imageService, CacheInterface $cache)
    {
        $this->imageService = $imageService;
        $this->cache = $cache;
    }

    /**
     * @Route("/", name="homepage")
     * @return Response
     */
    public function index(): Response
    {
        $images = $this->cache->get('cached_images', function (ItemInterface $item) {
            $item->expiresAfter(3600);
            return $this->imageService->loadImages();
        });

        return $this->render('default/index.html.twig', ['images' => $images]);
    }

}
