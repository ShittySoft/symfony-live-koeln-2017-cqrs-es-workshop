<?php

declare(strict_types=1);

namespace Building\App;

use Bernard\Driver\FlatFileDriver;
use Bernard\Producer;
use Bernard\Queue;
use Bernard\QueueFactory;
use Bernard\QueueFactory\PersistentFactory;
use Building\Domain\Aggregate\Building;
use Building\Domain\Command;
use Building\Domain\DomainEvent\CheckInAnomalyDetected;
use Building\Domain\DomainEvent\UserCheckedIn;
use Building\Domain\DomainEvent\UserCheckedOut;
use Building\Domain\Repository\BuildingRepositoryInterface;
use Building\Domain\Finder\IsUserBlacklistedInterface;
use Building\Infrastructure\Repository\BuildingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDOSqlite\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\SchemaException;
use Interop\Container\ContainerInterface;
use Prooph\Common\Event\ActionEvent;
use Prooph\Common\Event\ActionEventEmitter;
use Prooph\Common\Event\ActionEventListenerAggregate;
use Prooph\Common\Event\ProophActionEventEmitter;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\Common\Messaging\NoOpMessageConverter;
use Prooph\EventSourcing\AggregateChanged;
use Prooph\EventSourcing\EventStoreIntegration\AggregateTranslator;
use Prooph\EventStore\Adapter\Doctrine\DoctrineEventStoreAdapter;
use Prooph\EventStore\Adapter\Doctrine\Schema\EventStoreSchema;
use Prooph\EventStore\Adapter\PayloadSerializer\JsonPayloadSerializer;
use Prooph\EventStore\Aggregate\AggregateRepository;
use Prooph\EventStore\Aggregate\AggregateType;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Stream\StreamName;
use Prooph\EventStoreBusBridge\EventPublisher;
use Prooph\EventStoreBusBridge\TransactionManager;
use Prooph\ServiceBus\Async\MessageProducer;
use Prooph\ServiceBus\CommandBus;
use Prooph\ServiceBus\EventBus;
use Prooph\ServiceBus\Message\Bernard\BernardMessageProducer;
use Prooph\ServiceBus\Message\Bernard\BernardSerializer;
use Prooph\ServiceBus\MessageBus;
use Prooph\ServiceBus\Plugin\ServiceLocatorPlugin;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Zend\ServiceManager\ServiceManager;

require_once __DIR__ . '/vendor/autoload.php';

return new ServiceManager([
    'factories' => [
        Connection::class => function () {
            $connection = DriverManager::getConnection([
                'driverClass' => Driver::class,
                'path'        => __DIR__ . '/data/db.sqlite3',
            ]);

            try {
                $schema = $connection->getSchemaManager()->createSchema();

                EventStoreSchema::createSingleStream($schema, 'event_stream', true);

                foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
                    $connection->exec($sql);
                }
            } catch (SchemaException $ignored) {
            }

            return $connection;
        },

        EventStore::class                  => function (ContainerInterface $container) {
            $eventBus   = new EventBus();
            $eventStore = new EventStore(
                new DoctrineEventStoreAdapter(
                    $container->get(Connection::class),
                    new FQCNMessageFactory(),
                    new NoOpMessageConverter(),
                    new JsonPayloadSerializer()
                ),
                new ProophActionEventEmitter()
            );

            $eventBus->utilize(new class ($container, $container) implements ActionEventListenerAggregate
            {
                /**
                 * @var ContainerInterface
                 */
                private $eventHandlers;

                /**
                 * @var ContainerInterface
                 */
                private $projectors;

                public function __construct(
                    ContainerInterface $eventHandlers,
                    ContainerInterface $projectors
                ) {
                    $this->eventHandlers = $eventHandlers;
                    $this->projectors    = $projectors;
                }

                public function attach(ActionEventEmitter $dispatcher)
                {
                    $dispatcher->attachListener(MessageBus::EVENT_ROUTE, [$this, 'onRoute']);
                }

                public function detach(ActionEventEmitter $dispatcher)
                {
                    throw new \BadMethodCallException('Not implemented');
                }

                public function onRoute(ActionEvent $actionEvent)
                {
                    $messageName = (string) $actionEvent->getParam(MessageBus::EVENT_PARAM_MESSAGE_NAME);

                    $handlers = [];

                    $listeners  = $messageName . '-listeners';
                    $projectors = $messageName . '-projectors';

                    if ($this->projectors->has($projectors)) {
                        $handlers = array_merge($handlers, $this->eventHandlers->get($projectors));
                    }

                    if ($this->eventHandlers->has($listeners)) {
                        $handlers = array_merge($handlers, $this->eventHandlers->get($listeners));
                    }

                    if ($handlers) {
                        $actionEvent->setParam(EventBus::EVENT_PARAM_EVENT_LISTENERS, $handlers);
                    }
                }
            });

            (new EventPublisher($eventBus))->setUp($eventStore);

            return $eventStore;
        },

        'immediate-command-bus'                  => function (ContainerInterface $container) : CommandBus {
            $commandBus = new CommandBus();

            $commandBus->utilize(new ServiceLocatorPlugin($container));
            $commandBus->utilize(new class implements ActionEventListenerAggregate {
                public function attach(ActionEventEmitter $dispatcher)
                {
                    $dispatcher->attachListener(MessageBus::EVENT_ROUTE, [$this, 'onRoute']);
                }

                public function detach(ActionEventEmitter $dispatcher)
                {
                    throw new \BadMethodCallException('Not implemented');
                }

                public function onRoute(ActionEvent $actionEvent)
                {
                    $actionEvent->setParam(
                        MessageBus::EVENT_PARAM_MESSAGE_HANDLER,
                        (string) $actionEvent->getParam(MessageBus::EVENT_PARAM_MESSAGE_NAME)
                    );
                }
            });

            $transactionManager = new TransactionManager();
            $transactionManager->setUp($container->get(EventStore::class));

            $commandBus->utilize($transactionManager);

            return new class ($commandBus) extends CommandBus {
                /**
                 * @var CommandBus
                 */
                private $commandBus;

                public function __construct(CommandBus $commandBus)
                {
                    $this->commandBus = $commandBus;
                }

                public function dispatch($command)
                {
                    var_dump($command);

                    $this->commandBus->dispatch($command);
                }
            };
        },
        CommandBus::class => function (ContainerInterface $container) : CommandBus {
            $messageProducer = $container->get(MessageProducer::class);
            $commandBus = $container->get('immediate-command-bus');

            return new class ($messageProducer, $commandBus) extends CommandBus
            {
                private $messageProducer;
                private $immediate;
                public function __construct(MessageProducer $messageProducer, CommandBus $immediate)
                {
                    $this->messageProducer = $messageProducer;
                    $this->immediate = $immediate;
                }

                public function dispatch($command)
                {
                    if ($command instanceof Command\RegisterNewBuilding) {
                        $this->immediate->dispatch($command);

                        return;
                    }

                    $this->messageProducer->__invoke($command);
                }
            };
        },

        // ignore this - this is async stuff
        // we'll get to it later

        QueueFactory::class => function () : QueueFactory {
            return new PersistentFactory(
                new FlatFileDriver(__DIR__ . '/data/bernard'),
                new BernardSerializer(new FQCNMessageFactory(), new NoOpMessageConverter())
            );
        },

        Queue::class => function (ContainerInterface $container) : Queue {
            return $container->get(QueueFactory::class)->create('commands');
        },

        MessageProducer::class => function (ContainerInterface $container) : MessageProducer {
            return new BernardMessageProducer(
                new Producer($container->get(QueueFactory::class),new EventDispatcher()),
                'commands'
            );
        },

        // Command -> CommandHandlerFactory
        // this is where most of the work will be done (by you!)
        Command\RegisterNewBuilding::class => function (ContainerInterface $container) : callable {
            $buildings = $container->get(BuildingRepositoryInterface::class);

            return function (Command\RegisterNewBuilding $command) use ($buildings) {
                $buildings->add(Building::new($command->name()));
            };
        },
        Command\CheckInUser::class => function (ContainerInterface $container) : callable {
            $buildings = $container->get(BuildingRepositoryInterface::class);
            $blacklist = $container->get(IsUserBlacklistedInterface::class);

            return function (Command\CheckInUser $checkIn) use ($buildings, $blacklist) : void {
                $building = $buildings->get($checkIn->buildingId());

                $building->checkInUser($checkIn->username(), $blacklist);

                $buildings->add($building);
            };
        },
        Command\CheckOutUser::class => function (ContainerInterface $container) : callable {
            $buildings = $container->get(BuildingRepositoryInterface::class);

            return function (Command\CheckOutUser $checkOut) use ($buildings) : void {
                $building = $buildings->get($checkOut->buildingId());

                $building->checkOutUser($checkOut->username());

                $buildings->add($building);
            };
        },
        Command\NotifySecurityOfAnomaly::class => function () : callable {
            return function (Command\NotifySecurityOfAnomaly $notify) {
                error_log(sprintf('Yo, somebody is being fishy: %s %s', $notify->username(), $notify->buildingId()->toString()));
            };
        },
        CheckInAnomalyDetected::class . '-listeners' => function (ContainerInterface $container) : array {
            $commandBus = $container->get(CommandBus::class);

            return [
                function (CheckInAnomalyDetected $anomaly) use ($commandBus) {
                    $commandBus->dispatch(Command\NotifySecurityOfAnomaly::fromBuildingAndUsername(
                        $anomaly->buildingId(),
                        $anomaly->username()
                    ));
                },
            ];
        },
        UserCheckedIn::class . '-projectors' => function (ContainerInterface $container) : array {
            $eventStore = $container->get(EventStore::class);

            return [
                // naive solution following:
                function (UserCheckedIn $event) {
                    // produce state...
                    $file = __DIR__ . '/public/naive-' . $event->aggregateId() . '.json';

                    $users = [];

                    if (is_file($file)) {
                        $users = json_decode(file_get_contents($file), true);
                    }

                    file_put_contents($file, json_encode(array_values(array_unique(array_merge($users, [$event->username()])))));
                },
                // proper solution
                function (AggregateChanged $event) use ($eventStore) {
                    $users = [];

                    $events = $eventStore->loadEventsByMetadataFrom(
                        new StreamName('event_stream'),
                        ['aggregate_id' => $event->aggregateId()]
                    );

                    foreach ($events as $replayedEvent) {
                        if ($replayedEvent instanceof UserCheckedIn) {
                            $users[$replayedEvent->username()] = null;
                        }

                        if ($replayedEvent instanceof UserCheckedOut) {
                            unset($users[$replayedEvent->username()]);
                        }
                    }

                    file_put_contents(__DIR__ . '/public/proper-' . $event->aggregateId() . '.json', json_encode(array_keys($users)));
                },
            ];
        },
        UserCheckedOut::class . '-projectors' => function (ContainerInterface $container) : array {
            $eventStore = $container->get(EventStore::class);

            return [
                // naive solution following:
                function (UserCheckedOut $event) {
                    // produce state...
                    $file = __DIR__ . '/public/naive-' . $event->aggregateId() . '.json';

                    $users = [];

                    if (is_file($file)) {
                        $users = json_decode(file_get_contents($file), true);
                    }

                    $usersByName = array_flip($users);
                    unset($usersByName[$event->username()]);

                    file_put_contents($file, json_encode(array_keys($usersByName)));
                },
                // proper solution - duplicate of what we have in the UserCheckedIn listener
                // can be extracted to a service/class
                function (AggregateChanged $event) use ($eventStore) {
                    $users = [];

                    $events = $eventStore->loadEventsByMetadataFrom(
                        new StreamName('event_stream'),
                        ['aggregate_id' => $event->aggregateId()]
                    );

                    foreach ($events as $replayedEvent) {
                        if ($replayedEvent instanceof UserCheckedIn) {
                            $users[$replayedEvent->username()] = null;
                        }

                        if ($replayedEvent instanceof UserCheckedOut) {
                            unset($users[$replayedEvent->username()]);
                        }
                    }

                    file_put_contents(__DIR__ . '/public/proper-' . $event->aggregateId() . '.json', json_encode(array_keys($users)));
                },
            ];
        },
        BuildingRepositoryInterface::class => function (ContainerInterface $container) : BuildingRepositoryInterface {
            return new BuildingRepository(
                new AggregateRepository(
                    $container->get(EventStore::class),
                    AggregateType::fromAggregateRootClass(Building::class),
                    new AggregateTranslator()
                )
            );
        },
        IsUserBlacklistedInterface::class => function () {
            return new class implements IsUserBlacklistedInterface
            {
                public function __invoke(string $username) : bool
                {
                    return in_array($username, ['realDonaldTrump', 'osamaBinLaden'], true);
                }
            };
        }
    ],
]);