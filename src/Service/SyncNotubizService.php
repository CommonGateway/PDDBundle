<?php

namespace CommonGateway\WOOBundle\Service;

use App\Entity\Entity as Schema;
use App\Entity\Mapping;
use App\Entity\Endpoint;
use App\Entity\ObjectEntity;
use App\Service\ObjectEntityService;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use CommonGateway\CoreBundle\Service\HydrationService;
use CommonGateway\CoreBundle\Service\ValidationService;
use CommonGateway\CoreBundle\Service\CacheService;
use DateTime;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use App\Entity\Gateway as Source;
use Exception;

/**
 * Service responsible for synchronizing NotuBiz objects to woo objects.
 *
 * @author  Conduction BV <info@conduction.nl>, Wilco Louwerse <wilco@conduction.nl>.
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category Service
 */
class SyncNotubizService
{
    
    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $resourceService;
    
    /**
     * @var CallService
     */
    private CallService $callService;
    
    /**
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;
    
    /**
     * @var MappingService
     */
    private MappingService $mappingService;
    
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;
    
    /**
     * @var SymfonyStyle|null
     */
    private ?SymfonyStyle $style = null;
    
    /**
     * @var LoggerInterface $logger .
     */
    private LoggerInterface $logger;
    
    /**
     * @var ValidationService $validationService .
     */
    private ValidationService $validationService;
    
    /**
     * @var CacheService $cacheService .
     */
    private CacheService $cacheService;
    
    /**
     * @var ObjectEntityService
     */
    private ObjectEntityService $gatewayOEService;
    
    /**
     * @var WooService
     */
    private WooService $wooService;
    
    /**
     * @var HydrationService
     */
    private HydrationService $hydrationService;
    
    /**
     * @var array
     */
    private array $data;
    
    /**
     * @var array
     */
    private array $configuration;
    
    
    /**
     * SyncNotubizService constructor.
     *
     * @param GatewayResourceService $resourceService
     * @param CallService $callService
     * @param SynchronizationService $syncService
     * @param EntityManagerInterface $entityManager
     * @param MappingService $mappingService
     * @param LoggerInterface $pluginLogger
     * @param ValidationService $validationService
     * @param CacheService $cacheService
     * @param ObjectEntityService $gatewayOEService
     * @param WooService $wooService
     */
    public function __construct(
        GatewayResourceService $resourceService,
        CallService            $callService,
        SynchronizationService $syncService,
        EntityManagerInterface $entityManager,
        MappingService         $mappingService,
        LoggerInterface        $pluginLogger,
        ValidationService      $validationService,
        CacheService           $cacheService,
        ObjectEntityService    $gatewayOEService,
        WooService             $wooService
    )
    {
        $this->resourceService = $resourceService;
        $this->callService = $callService;
        $this->syncService = $syncService;
        $this->entityManager = $entityManager;
        $this->mappingService = $mappingService;
        $this->logger = $pluginLogger;
        $this->validationService = $validationService;
        $this->cacheService = $cacheService;
        $this->gatewayOEService = $gatewayOEService;
        $this->wooService = $wooService;
        $this->hydrationService = new HydrationService($this->syncService, $this->entityManager);
        
    }//end __construct()
    
    
    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $style
     *
     * @return self
     */
    public function setStyle(SymfonyStyle $style): self
    {
        $this->style = $style;
        
        return $this;
        
    }//end setStyle()
    
    
    /**
     * Fetches Event objects from NotuBiz.
     *
     * @param Source $source The source entity that provides the source of the result data.
     * @param int|null $page The page we are fetching, increments each iteration.
     * @param array $results The results from NotuBiz api we merge each iteration.
     *
     * @return array The fetched objects.
     */
    private function fetchObjects(Source $source, ?int $page = 1, array $results = []): array
    {
        $dateTo = new DateTime();
        $dateFrom = new DateTime();
        $dateFrom->add(DateInterval::createFromDateString('-10 years'));
        
        $query = [
            'format' => 'json',
            'page' => $page,
            'organisation_id' => $this->configuration['organisationId'],
            'version' => ($this->configuration['notubizVersion'] ?? '1.21.1'),
            'date_to' => $dateTo->format('Y-m-d H:i:s'),
            'date_from' => $dateFrom->format('Y-m-d H:i:s'),
        ];
        
        if (isset($this->configuration['gremiaIds']) === true) {
            $query['gremia_ids'] = $this->configuration['gremiaIds'];
        }
        
        try {
            $response = $this->callService->call($source, $this->configuration['sourceEndpoint'], 'GET', ['query' => $query]);
            $decodedResponse = $this->callService->decodeResponse($source, $response);
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error('Something wen\'t wrong fetching ' . $source->getLocation() . $this->configuration['sourceEndpoint'] . ': ' . $e->getMessage());
            $this->logger->error('Something wen\'t wrong fetching ' . $source->getLocation() . $this->configuration['sourceEndpoint'] . ': ' . $e->getMessage(), ['plugin' => 'common-gateway/woo-bundle']);
            
            return [];
        }
        
        $results = array_merge($results, $decodedResponse['events']);
        
        // Pagination NotuBiz.
        if (isset($decodedResponse['pagination']['has_more_pages']) === true && $decodedResponse['pagination']['has_more_pages'] === true) {
            $page++;
            $results = $this->fetchObjects($source, $page, $results);
        }
        
        return $results;
        
    }//end fetchObjects()
    
    
    /**
     * Fetches a single Event object from NotuBiz.
     *
     * @param Source $source The source entity that provides the source of the result data.
     * @param string $id The id of and Event from the NotuBiz API to get.
     *
     * @return array The fetched object.
     */
    private function fetchObject(Source $source, string $id): array
    {
        $query = ['format' => 'json'];
        
        $endpoint = $this->configuration['sourceEndpoint'] . '/' . $id;
        
        try {
            $response = $this->callService->call($source, $endpoint, 'GET', ['query' => $query]);
            $decodedResponse = $this->callService->decodeResponse($source, $response);
            
            $result = $decodedResponse['event'][0];
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error('Something wen\'t wrong fetching ' . $source->getLocation() . $endpoint . ': ' . $e->getMessage());
            $this->logger->error('Something wen\'t wrong fetching ' . $source->getLocation() . $endpoint . ': ' . $e->getMessage(), ['plugin' => 'common-gateway/woo-bundle']);
            
            return ["Message" => 'Something wen\'t wrong fetching ' . $source->getLocation() . $endpoint . ': ' . $e->getMessage()];
        }
        
        if ((string) $result['organisation'] !== (string) $this->configuration['organisationId']) {
            $this->logger->info('Fetched Notubiz Event does not match the organisationId of the Action', ['plugin' => 'common-gateway/woo-bundle']);
            return ["Message" => 'Fetched Notubiz Event does not match the organisationId of the Action'];
        }
        
        if (isset($this->configuration['gremiaIds']) === true) {
            // todo check if gremium id is allowed, we need to fetch meeting object of the Event in orde to check this
            // todo do want to do this here, because we will do the same api-call again later, prevent doing this twice somehow?
            // $meetingObject = $this->fetchMeeting($source, $id);
            // if (in_array($meetingObject['gremium']['id'], $this->configuration['gremiaIds']) === false) {
            // $this->logger->info('Fetched Notubiz Event (Meeting) does not match one of the valid gremium id\'s configured in the Action', ['plugin' => 'common-gateway/woo-bundle']);
            // return [];
            // }
        }
        
        return $result;
        
    }//end fetchObject()
    
    
    /**
     * Deletes a single Synchronization and its object(s) from the gateway.
     *
     * @param array $config An array containing the Source, Mapping and Schema we need in order to sync/delete.
     * @param string $id The id of and Event from the NotuBiz API (sourceId) to find a Synchronization with in the gateway.
     *
     * @return array An array with a success message or error message.
     */
    private function deleteObject(array $config, string $id, string $categorie=null): array
    {
        // Make sure this object does no longer exist in the Notubiz source.
        $result = $this->fetchObject($config['source'], $id);
        if (count($result) !== 1 || isset($result['Message']) === false) {
            return ["Message" => "Object still exists in the NotuBiz API, object in the gateway did not get deleted"];
        }
        
        $synchronization = $this->syncService->findSyncBySource($config['source'], $config['schema'], $id);
        
        if ($categorie !== null && $synchronization->getObject()->getValue('categorie') !== $categorie) {
            return ["Message" => "Object does not match the categorie: $categorie"];
        }
        
        $this->entityManager->remove($synchronization->getObject());
        $this->entityManager->flush();
        
        return ["Message" => "Object deleted successfully"];
    }


    /**
     * Gets the custom fields for creating a publication object.
     *
     * @param string $categorie The categorie for this publication object.
     *
     * @return array The custom fields.
     */
    private function getCustomFields(string $categorie): array
    {
        return [
            'organisatie' => [
                'oin'  => $this->configuration['oin'],
                'naam' => $this->configuration['organisatie'],
            ],
            'categorie'   => $categorie,
            'autoPublish' => $this->configuration['autoPublish'] ?? true,
        ];

    }//end getCustomFields()


    /**
     * Fetches meeting object for an Event from NotuBiz.
     *
     * @param Source $source The source entity that provides the source of the result data.
     * @param string $id     The id of and Event from the NotuBiz API to get the Metting object for.
     *
     * @return array|null The fetched meeting object.
     */
    private function fetchMeeting(Source $source, string $id): ?array
    {
        $endpoint = "/events/meetings/$id";

        try {
            $response        = $this->callService->call($source, $endpoint, 'GET', ['query' => ['format' => 'json']]);
            $decodedResponse = $this->callService->decodeResponse($source, $response);
        } catch (Exception $e) {
            isset($this->style) === true && $this->style->error('Something wen\'t wrong fetching '.$source->getLocation().$endpoint.': '.$e->getMessage());
            $this->logger->error('Something wen\'t wrong fetching '.$source->getLocation().$endpoint.': '.$e->getMessage(), ['plugin' => 'common-gateway/woo-bundle']);

            return [];
        }

        return $decodedResponse['meeting'];

    }//end fetchMeeting()


    /**
     * Syncs a single result from the Notubiz source.
     *
     * @param array $meetingObject The meetingObject of the Event we are syncing.
     * @param array $config        An array containing the Source, Mapping and Schema we need in order to sync.
     * @param array $result        The result array to map and sync
     *
     * @return string|ObjectEntity|array|null
     */
    private function syncResult(array $meetingObject, array $config, array $result): ObjectEntity|array|string|null
    {
        if (isset($meetingObject['documents']) === true) {
            $result['bijlagen'] = $meetingObject['documents'];
            foreach ($meetingObject['agenda_items'] as $agenda_item) {
                $result['bijlagen'] = array_merge($result['bijlagen'], $agenda_item['documents']);
            }
        }

        $mappedResult = $this->mappingService->mapping($config['mapping'], $result);

        $validationErrors = $this->validationService->validateData($mappedResult, $config['schema'], 'POST');
        if ($validationErrors !== null) {
            $validationErrors = implode(', ', $validationErrors);
            $this->logger->warning("SyncNotubiz validation errors: $validationErrors", ['plugin' => 'common-gateway/woo-bundle']);
            isset($this->style) === true && $this->style->warning("SyncNotubiz validation errors: $validationErrors");

            return 'continue';
        }

        return $this->hydrationService->searchAndReplaceSynchronizations(
            $mappedResult,
            $config['source'],
            $config['schema'],
            false,
            false
        );

    }//end syncResult()


    /**
     * Dispatches an event for creating all files / bijlagen.
     *
     * @param array  $documents All the documents to create files for.
     * @param Source $source    The sources used to get the documents.
     *
     * @return void
     */
    private function handleDocuments(array $documents, Source $source)
    {
        foreach ($documents as $document) {
            $documentData['document'] = $document;
            $documentData['source']   = $source->getReference();
            $this->gatewayOEService->dispatchEvent('commongateway.action.event', $documentData, 'woo.openwoo.document.created');
        }

    }//end handleDocuments()


    /**
     * Builds the response in the data array and returns it.
     *
     * @return array The data array.
     */
    private function returnResponse(array $responseItems, Source $source, int $deletedObjectsCount): array
    {
        $this->data['response'] = new Response(json_encode($responseItems), 200);

        $countItems = count($responseItems);
        $logMessage = "Synchronized $countItems events to woo objects for ".$source->getName()." and deleted $deletedObjectsCount objects";
        isset($this->style) === true && $this->style->success($logMessage);
        $this->logger->info($logMessage, ['plugin' => 'common-gateway/woo-bundle']);

        return $this->data;

    }//end returnResponse()


    /**
     * Handles syncing the Event object results we got from the Notubiz source to the gateway.
     *
     * @param array $results The array of results form the source.
     * @param array $config  An array containing the Source, Mapping and Schema we need in order to sync.
     *
     * @return array
     */
    private function handleResults(array $results, array $config): array
    {
        $categorie = "Vergaderstukken decentrale overheden";
        // todo: or maybe: "Agenda's en besluitenlijsten bestuurscolleges"
        $customFields = $this->getCustomFields($categorie);

        $documents = $idsSynced = $responseItems = [];
        foreach ($results as $result) {
            try {
                $result        = array_merge($result, $customFields);
                $meetingObject = $this->fetchMeeting($config['source'], $result['id']);

                $object = $this->syncResult($meetingObject, $config, $result);
                if ($object === 'continue') {
                    continue;
                }

                // Get all synced sourceIds.
                if (empty($object->getSynchronizations()) === false && $object->getSynchronizations()[0]->getSourceId() !== null) {
                    $idsSynced[] = $object->getSynchronizations()[0]->getSourceId();
                }

                $this->entityManager->persist($object);
                $this->cacheService->cacheObject($object);
                $responseItems[] = $object;

                $renderedObject = $object->toArray();
                $documents      = array_merge($documents, $renderedObject['bijlagen']);
            } catch (Exception $exception) {
                $this->logger->error("Something went wrong synchronizing sourceId: {$result['id']} with error: {$exception->getMessage()}", ['plugin' => 'common-gateway/woo-bundle']);
                continue;
            }//end try
        }//end foreach

        $this->entityManager->flush();

        $this->handleDocuments($documents, $config['source']);

        $deletedObjectsCount = $this->wooService->deleteNonExistingObjects($idsSynced, $config['source'], $this->configuration['schema'], $categorie);

        return $this->returnResponse($responseItems, $config['source'], $deletedObjectsCount);

    }//end handleResults()


    /**
     * Handles syncing a single Event object result we got from the Notubiz source to the gateway.
     *
     * @param array $result The result form the source.
     * @param array $config An array containing the Source, Mapping and Schema we need in order to sync.
     *
     * @return array
     */
    private function handleResult(array $result, array $config): array
    {
        $categorie = "Vergaderstukken decentrale overheden";
        // todo: or maybe: "Agenda's en besluitenlijsten bestuurscolleges"
        $customFields = $this->getCustomFields($categorie);

        $result        = array_merge($result, $customFields);
        $meetingObject = $this->fetchMeeting($config['source'], $this->data['body']['resourceId']);
        if (empty($meetingObject) === true) {
            return ["Message" => "Something went wrong fetching the Meeting object for Event {$this->data['body']['resourceId']}, check error logs for more info"];
        }

        // Make sure we add id to the result so the Synchronization uses the correct SourceId
        $result['id'] = $this->data['body']['resourceId'];
        // Use creation_date of meeting because Event doesn't have this field when getting one single Event object.
        $result['creation_date'] = $meetingObject['creation_date'];

        $object = $this->syncResult($meetingObject, $config, $result);
        if ($object === 'continue') {
            return ["Message" => "Validation errors, check warning logs for more info"];
        }

        $this->entityManager->persist($object);
        $this->cacheService->cacheObject($object);

        $renderedObject = $object->toArray();

        $this->entityManager->flush();

        $this->handleDocuments($renderedObject['bijlagen'], $config['source']);

        $this->logger->info("Synchronized Event {$this->data['body']['resourceUrl']} to woo object", ['plugin' => 'common-gateway/woo-bundle']);

        return $renderedObject;

    }//end handleResult()


    /**
     * Validates if the Configuration array has the required information to sync from Notubiz to OpenWoo.
     *
     * @return array|null The source, schema and mapping objects if they exist. Null if configuration array does not contain all required fields.
     */
    private function validateConfiguration(): ?array
    {
        if ($this->wooService->validateHandlerConfig(
            $this->configuration,
            [
                'sourceEndpoint',
                'organisationId',
            ],
            'sync Notubiz'
        ) === false
        ) {
            return null;
        }

        $source  = $this->resourceService->getSource($this->configuration['source'], 'common-gateway/woo-bundle');
        $schema  = $this->resourceService->getSchema($this->configuration['schema'], 'common-gateway/woo-bundle');
        $mapping = $this->resourceService->getMapping($this->configuration['mapping'], 'common-gateway/woo-bundle');
        if ($source instanceof Source === false
            || $schema instanceof Schema === false
            || $mapping instanceof Mapping === false
        ) {
            isset($this->style) === true && $this->style->error("{$this->configuration['source']}, {$this->configuration['schema']} or {$this->configuration['mapping']} not found, ending sync NotuBiz");
            $this->logger->error("{$this->configuration['source']}, {$this->configuration['schema']} or {$this->configuration['mapping']} not found, ending sync NotuBiz", ['plugin' => 'common-gateway/woo-bundle']);

            return null;
        }//end if

        return [
            "source"  => $source,
            "schema"  => $schema,
            'mapping' => $mapping,
        ];

    }//end validateConfiguration()


    /**
     * Handles the synchronization of Notubiz API Event objects to OpenWoo publicatie objects.
     *
     * @param array $data
     * @param array $configuration
     *
     * @throws CacheException|InvalidArgumentException
     *
     * @return array
     */
    public function syncNotubizHandler(array $data, array $configuration): array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        isset($this->style) === true && $this->style->success('syncNotubizHandler triggered');
        $this->logger->info('syncNotubizHandler triggered', ['plugin' => 'common-gateway/woo-bundle']);

        // Check if configuration array contains the required data and check if source, schema and mapping exist.
        $config = $this->validateConfiguration();
        if ($config === null) {
            return [];
        }

        isset($this->style) === true && $this->style->info("Fetching objects from {$config['source']->getLocation()}");
        $this->logger->info("Fetching objects from {$config['source']->getLocation()}", ['plugin' => 'common-gateway/woo-bundle']);

        $results = $this->fetchObjects($config['source']);
        if (empty($results) === true) {
            $this->logger->info('No results found, ending syncNotubizHandler', ['plugin' => 'common-gateway/woo-bundle']);
            return $this->data;
        }

        return $this->handleResults($results, $config);

    }//end syncNotubizHandler()


    /**
     * Handles the synchronization of one single Notubiz API Event object to an OpenWoo publicatie object when a notification got triggered.
     *
     * @param array $data
     * @param array $configuration
     *
     * @return array
     */
    public function handleNotification(array $data, array $configuration): array
    {
        $this->data          = $data;
        $this->configuration = $configuration;

        // Check if configuration array contains the required data and check if source, schema and mapping exist.
        $config = $this->validateConfiguration();
        if ($config === null) {
            return [];
        }

        if ($this->data['body']['actie'] === 'delete') {
            return $this->deleteObject($config, $this->data['body']['resourceId']);
        }

        $this->logger->info("Fetching object {$this->data['body']['resourceUrl']}", ['plugin' => 'common-gateway/woo-bundle']);

        $result = $this->fetchObject($config['source'], $this->data['body']['resourceId']);
        if (count($result) === 1 && isset($result['Message']) === true) {
            $this->logger->info('No result found, stop handling notification for Notubiz sync', ['plugin' => 'common-gateway/woo-bundle']);
            return $result;
        }

        return $this->handleResult($result, $config);

    }//end handleNotification()


}//end class
