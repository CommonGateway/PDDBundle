<?php

namespace CommonGateway\WOOBundle\Service;

use Adbar\Dot;
use App\Entity\Entity as Schema;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Entity\Value;
use App\Entity\Endpoint;
use App\Entity\File;
use App\Service\ApplicationService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\DownloadService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use App\Entity\Gateway as Source;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

/**
 * Service responsible for woo sitemaps, sitemapindex and robot.txt.
 *
 * @author  Conduction BV <info@conduction.nl>, Sarai Misidjan <sarai@conduction.nl>, Wilco Louwerse <wilco@conduction.nl>.
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category Service
 */
class SitemapService
{

    /**
     * @var EntityManagerInterface $entityManager
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var RequestStack $requestStack
     */
    private RequestStack $requestStack;

    /**
     * @var LoggerInterface $logger.
     */
    private LoggerInterface $logger;

    /**
     * @var GatewayResourceService $resourceService.
     */
    private GatewayResourceService $resourceService;

    /**
     * @var CacheService $cacheService.
     */
    private CacheService $cacheService;

    /**
     * @var MappingService $mappingService.
     */
    private MappingService $mappingService;

    /**
     * @var DownloadService $downloadService.
     */
    private DownloadService $downloadService;

    /**
     * @var ApplicationService $applicationService.
     */
    private ApplicationService $applicationService;

    /**
     * @var array $data
     */
    private array $data;

    /**
     * @var array $configuration
     */
    private array $configuration;


    /**
     * SitemapService constructor.
     *
     * @param EntityManagerInterface $entityManager      The Entity Manager Interface
     * @param RequestStack           $requestStack       The Request Stack
     * @param LoggerInterface        $pluginLogger       The Logger Interface
     * @param GatewayResourceService $resourceService    The Gateway Resource Service
     * @param CacheService           $cacheService       The Cache Service
     * @param MappingService         $mappingService     The Mapping Service
     * @param DownloadService        $downloadService    The Download Service
     * @param ApplicationService     $applicationService The Application Service
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        LoggerInterface $pluginLogger,
        GatewayResourceService $resourceService,
        CacheService $cacheService,
        MappingService $mappingService,
        DownloadService $downloadService,
        ApplicationService $applicationService
    ) {
        $this->entityManager      = $entityManager;
        $this->requestStack       = $requestStack;
        $this->logger             = $pluginLogger;
        $this->resourceService    = $resourceService;
        $this->cacheService       = $cacheService;
        $this->mappingService     = $mappingService;
        $this->downloadService    = $downloadService;
        $this->applicationService = $applicationService;

    }//end __construct()


    /**
     * Generates a sitemap, sitemapindex or robot.txt for the given organization
     *
     * @param array $data          The data passed by the action.
     * @param array $configuration The configuration of the action.
     *
     * @return array
     */
    public function sitemapHandler(array $data, array $configuration): array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        // Get the type from the action so that we know what to generate.
        if (key_exists('type', $this->configuration) === false) {
            $this->logger->error('The type in the configuration of the action is not set.', ['plugin' => 'common-gateway/woo-bundle']);
            $this->data['response'] = $this->createResponse(['Message' => 'The type in the configuration of the action is not set.'], 409, 'error');
            return $this->data;
        }

        // Get the parameters from the call. This has to be any identification for an organization.
        $parameters = array_merge($this->data['path'], $this->data['query']);

        switch ($this->configuration['type']) {
        case 'sitemap':
            return $this->getSitemap($parameters);
        case 'sitemapindex':
            return $this->getSitemapindex($parameters);
        case 'robot.txt':
            return $this->getRobot($parameters);
        default:
            $this->logger->error('Invalid action configuration type.', ['plugin' => 'common-gateway/woo-bundle']);
        }

        $this->data['response'] = $this->createResponse(['Message' => 'Invalid action configuration type.'], 409, 'error');
        return $this->data;

    }//end sitemapHandler()


    /**
     * Finds all subobjects of the type 'bijlage' for a specified object of type 'publicatie'.
     *
     * @param array $object The 'publicatie' object to find documents for.
     *
     * @return array The resulting 'bijlage' subobjects.
     */
    private function getAllDocumentsForObject(array $object): array
    {
        $documents = array_filter(
            $object['bijlagen'],
            function ($bijlage) {
                return isset($bijlage['url']) === true;
            }
        );

        // Make sure to check if url is set before adding it to the list of documents!
        if (isset($object['metadata']['informatieverzoek']['verzoek']['url']) === true) {
            $documents[] = $object['metadata']['informatieverzoek']['verzoek'];
        }

        // Make sure to check if url is set before adding it to the list of documents!
        if (isset($object['metadata']['informatieverzoek']['besluit']['url']) === true) {
            $documents[] = $object['metadata']['informatieverzoek']['besluit'];
        }

        return $documents;

    }//end getAllDocumentsForObject()


    /**
     * Generates a sitemap for the given organization
     *
     * @param array $parameters The parameter array from the request.
     *
     * @return array Handler data with added 'response'.
     */
    private function getSitemap(array $parameters): array
    {
        // Get the publication schema and the sitemap mapping.
        $mapping          = $this->resourceService->getMapping('https://commongateway.nl/mapping/woo.sitemap.mapping.json', 'common-gateway/woo-bundle');
        $publicatieSchema = $this->resourceService->getSchema('https://commongateway.nl/woo.publicatie.schema.json', 'common-gateway/woo-bundle');
        if ($publicatieSchema instanceof Schema === false || $mapping instanceof Mapping === false) {
            $this->logger->error('The publication schema or the sitemap mapping cannot be found.', ['plugin' => 'common-gateway/woo-bundle']);
            $this->data['response'] = $this->createResponse(['Message' => 'The publication schema or the sitemap mapping cannot be found.'], 409, 'error', true);
            return $this->data;
        }

        // todo: This is a temp fix for dealing with amp; but we should probably be using htmlspecialchars_decode on the full url somehow.
        foreach ($parameters as $key => $value) {
            $newKey = str_replace('amp;', '', $key);
            if ($newKey !== $key) {
                $parameters[$newKey] = $value;
                unset($parameters[$key]);
            }
        }

        $filter = array_merge(
            $parameters,
            [
                'organisatie.oin' => $parameters['oin'],
                '_limit'          => 50000,
            ]
        );

        $publisherSchema = $this->resourceService->getSchema('https://commongateway.nl/woo.sitemap.schema.json', 'common-gateway/woo-bundle');
        $publishers      = $this->cacheService->searchObjects(['oin' => $parameters['oin']], [$publisherSchema->getId()->toString()])['results'];

        if (count($publishers) === 0) {
            $this->logger->error('Couldn\'t find a publisher for this oin: '.$parameters['oin'], ['plugin' => 'common-gateway/woo-bundle']);
            $this->data['response'] = $this->createResponse(['Message' => 'Couldn\'t find a publisher for this oin: '.$parameters['oin']], 404, 'error', true);
            return $this->data;
        }

        unset($filter['oin'], $filter['sitemaps'], $filter['sitemap']);

        // $filter = ['_limit' => 50000];
        // Get all the publication objects with the given query.
        $objects = $this->cacheService->searchObjects($filter, [$publicatieSchema->getId()->toString()])['results'];

        $sitemap = [];
        foreach ($objects as $object) {
            $objectArray = json_decode(json_encode($object), true);
            $documents   = $this->getAllDocumentsForObject($objectArray);

            $publisher             = [];
            $publisher['name']     = ($objectArray['organisatie']['naam'] ?? '');
            $publisher['resource'] = $publishers[0]['organisatiecode'];

            $mappedObject = $this->mappingService->mapping($mapping, ['object' => $objectArray, 'documents' => $documents, 'publisher' => $publisher]);

            $sitemap = array_merge_recursive($sitemap, $mappedObject);
        }

        // Return the sitemap response.
        $this->data['response'] = $this->createResponse($sitemap, 200, 'urlset', true);
        return $this->data;

    }//end getSitemap()


    /**
     * Generates a sitemapindex for the given organization
     *
     * @param array $parameters The query array from the request.
     *
     * @return array Handler data with added 'response'.
     */
    private function getSitemapindex(array $parameters): array
    {
        $mapping          = $this->resourceService->getMapping('https://commongateway.nl/mapping/woo.sitemapindex.mapping.json', 'common-gateway/woo-bundle');
        $publicatieSchema = $this->resourceService->getSchema('https://commongateway.nl/woo.publicatie.schema.json', 'common-gateway/woo-bundle');
        $categorieMapping = $this->resourceService->getMapping('https://commongateway.nl/mapping/woo.sitemapindex.informatiecategorie.mapping.json', 'common-gateway/woo-bundle');

        if ($publicatieSchema instanceof Schema === false || $mapping instanceof Mapping === false || $categorieMapping instanceof Mapping === false) {
            $this->logger->error('The publication schema, the sitemap index mapping or categorie mapping cannot be found.');
            $this->data['response'] = $this->createResponse(['Message' => 'The publication schema, the sitemap index mapping or categorie mapping cannot be found.'], 409, 'error');
            return $this->data;
        }

        $filter = $parameters;
        if ($parameters['oin'] === '00000000000000000000') {
            $filter = array_merge($filter, ['organisatie.oin' => $parameters['oin']]);
        }

        unset($filter['oin']);

        $categorieStr = '';
        if (isset($parameters['sitemapindex']) === true) {
            $categorie    = $this->mappingService->mapping($categorieMapping, [$parameters['sitemapindex'] => '']);
            $categorieDot = new Dot($categorie);

            if ($categorieDot->has($parameters['sitemapindex']) === false) {
                $this->logger->error('Invalid informatiecategorie.');
                $this->data['response'] = $this->createResponse(['Message' => 'Invalid informatiecategorie.'], 400, 'error');
                return $this->data;
            }

            $filter['categorie'] = $categorieDot->get($parameters['sitemapindex']);
            $categorieStr        = 'categorie='.urlencode($categorieDot->get($parameters['sitemapindex']));
            unset($filter['sitemapindex']);
        }

        // Count all the publication objects with the given query.
        $count = $this->cacheService->countObjects($filter, [$publicatieSchema->getId()->toString()]);
        $pages = ((int) (($count - 1) / 50000) + 1);

        // Get the domain of the request.
        $domain = $this->requestStack->getMainRequest()->getSchemeAndHttpHost();

        $sitemapindex = [];
        for ($i = 1; $i <= $pages; $i++) {
            // The location of the sitemap file is the endpoint of the sitemap.
            $location['location']      = $this->nonAsciiUrlEncode(
                $domain.'/api/sitemaps/'.$parameters['oin'].'/sitemap?'.$categorieStr.'&_page='.$i
            );
            $sitemapindex['sitemap'][] = $this->mappingService->mapping($mapping, $location);
        }

        // Return the sitemapindex response.
        $this->data['response'] = $this->createResponse($sitemapindex, 200, 'sitemapindex');
        return $this->data;

    }//end getSitemapindex()


    /**
     * Generates a robot.txt for the given organization
     *
     * @param array $parameters The query array from the request.
     *
     * @return array Handler data with added 'response'.
     */
    private function getRobot(array $parameters): array
    {

        $sitemapSchema    = $this->resourceService->getSchema('https://commongateway.nl/woo.sitemap.schema.json', 'common-gateway/woo-bundle');
        $categorieMapping = $this->resourceService->getMapping('https://commongateway.nl/mapping/woo.sitemapindex.informatiecategorie.mapping.json', 'common-gateway/woo-bundle');
        if ($sitemapSchema instanceof Schema === false || $categorieMapping instanceof Mapping === false) {
            $this->logger->error('The sitemap schema or categorie mapping cannot be found.', ['plugin' => 'common-gateway/woo-bundle']);
            $this->data['response'] = $this->createResponse(['Message' => 'The sitemap schema or categorie mapping cannot be found.'], 409, 'error');
            return $this->data;
        }

        $host = $this->requestStack->getMainRequest()->getHost();

        $sitemaps = $this->cacheService->searchObjects(['domains' => $host], [$sitemapSchema->getId()->toString()]);

        if (count($sitemaps['results']) === 1) {
            $oin = $sitemaps['results'][0]['oin'];
        } else if (count($sitemaps['results']) > 1) {
            $oin = '00000000000000000000';
            if (isset($parameters['oin']) === true) {
                $oin = $parameters['oin'];
            }
        } else {
            $this->logger->warning('No oin found for this domain, returning no sitemaps');
            $this->data['response'] = $this->createResponse(['Message' => "No oin found for this domain: $host"], 404, 'error');
            return $this->data;
        }

        $categories = array_keys($categorieMapping->getMapping());

        // Get the domain of the request.
        $domain = $this->requestStack->getMainRequest()->getSchemeAndHttpHost();

        foreach ($categories as $category) {
            // The location of the robot.txt file is the endpoint of the sitemapindex.
            $robotArray['locations'][] = $this->nonAsciiUrlEncode(
                $domain.'/api/sitemaps/'.$oin.'/'.$category,
                false
            );
        }

        // Set the id of the schema to the array so that the downloadService can work with that.
        $robotArray['_self']['schema']['id'] = $sitemapSchema->getId()->toString();
        $robot                               = $this->downloadService->render($robotArray);

        $this->data['response'] = new Response($robot, 200, ['Content-Type' => 'text/plain']);
        $this->data['response']->headers->set('Content-Disposition', 'attachment; filename="Robot.txt"');

        return $this->data;

    }//end getRobot()


    /**
     * URL encodes all characters in a string that are non ASCII characters.
     * And does a htmlspecialchars() on $str after that unless $htmlspecialchars is set to false.
     *
     * @param string $str              The input string.
     * @param bool   $htmlspecialchars True by default, if set to false htmlspecialchars() will not be used on $str.
     *
     * @return string The updated string.
     */
    private function nonAsciiUrlEncode(string $str, bool $htmlspecialchars=true): string
    {
        $str = preg_replace_callback(
            '/[^\x20-\x7e]/',
            function ($matches) {
                return urlencode($matches[0]);
            },
            $str
        );

        if ($htmlspecialchars === false) {
            return $str;
        }

        return htmlspecialchars($str);

    }//end nonAsciiUrlEncode()


    /**
     * Creates a response based on content.
     *
     * @param array  $content  The content to incorporate in the response
     * @param int    $status   The status code of the response
     * @param string $rootName The rootName of the xml.
     * @param bool   $sitemap  True if this is a response for the sitemap api call.
     *
     * @return Response
     */
    private function createResponse(array $content, int $status, string $rootName, bool $sitemap=false): Response
    {
        $this->logger->debug('Creating XML response', ['plugin' => 'common-gateway/woo-bundle']);
        $xmlEncoder = new XmlEncoder(['xml_root_node_name' => $rootName]);
        $xml        = ['@xmlns' => 'http://www.sitemaps.org/schemas/sitemap/0.9'];
        if ($sitemap === true) {
            $xml = array_merge(
                $xml,
                [
                    '@xmlns:xsi'          => 'http://www.w3.org/2001/XMLSchema-instance',
                    '@xmlns:diwoo'        => 'https://standaarden.overheid.nl/diwoo/metadata/',
                    '@xsi:schemaLocation' => 'http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd https://standaarden.overheid.nl/diwoo/metadata/ https://standaarden.overheid.nl/diwoo/metadata/0.9.1/xsd/diwoo-metadata.xsd',
                    '@xmlns:xhtml'        => 'http://www.w3.org/1999/xhtml',
                    '@xmlns:image'        => 'http://www.google.com/schemas/sitemap-image/1.1',
                    '@xmlns:video'        => 'http://www.google.com/schemas/sitemap-video/1.1',
                    '@xmlns:news'         => 'http://www.google.com/schemas/sitemap-news/0.9',
                ]
            );
        }

        $content = array_merge($xml, $content);

        $contentString = $xmlEncoder->encode($content, 'xml', ['xml_encoding' => 'utf-8', 'remove_empty_tags' => true]);

        // Remove CDATA
        $contentString = str_replace(["<![CDATA[", "]]>"], "", $contentString);

        $contentType = "application/xml";
        if (isset($this->data['headers']['Accept']) === true) {
            $contentType = $this->data['headers']['Accept'];
        }

        return new Response($contentString, $status, ['Content-Type' => $contentType]);

    }//end createResponse()


}//end class
