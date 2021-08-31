<?php

namespace RTippin\Messenger\Support;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use RTippin\Messenger\Actions\BaseMessengerAction;
use RTippin\Messenger\Actions\Messages\AddReaction;
use RTippin\Messenger\Actions\Messages\StoreAudioMessage;
use RTippin\Messenger\Actions\Messages\StoreDocumentMessage;
use RTippin\Messenger\Actions\Messages\StoreImageMessage;
use RTippin\Messenger\Actions\Messages\StoreMessage;
use RTippin\Messenger\Actions\Threads\MarkParticipantRead;
use RTippin\Messenger\Actions\Threads\SendKnock;
use RTippin\Messenger\Contracts\BroadcastDriver;
use RTippin\Messenger\Contracts\MessengerProvider;
use RTippin\Messenger\Exceptions\FeatureDisabledException;
use RTippin\Messenger\Exceptions\InvalidProviderException;
use RTippin\Messenger\Exceptions\KnockException;
use RTippin\Messenger\Exceptions\MessengerComposerException;
use RTippin\Messenger\Exceptions\ReactionException;
use RTippin\Messenger\Messenger;
use RTippin\Messenger\Models\Message;
use RTippin\Messenger\Models\Participant;
use RTippin\Messenger\Models\Thread;
use RTippin\Messenger\Repositories\PrivateThreadRepository;
use Throwable;

class MessengerComposer
{
    /**
     * @var Messenger
     */
    private Messenger $messenger;

    /**
     * @var BroadcastDriver
     */
    private BroadcastDriver $broadcaster;

    /**
     * @var DatabaseManager
     */
    private DatabaseManager $database;

    /**
     * @var PrivateThreadRepository
     */
    private PrivateThreadRepository $locator;

    /**
     * @var null|Thread|MessengerProvider
     */
    private $to = null;

    /**
     * @var bool
     */
    private bool $silent = false;

    /**
     * MessengerComposer constructor.
     *
     * @param Messenger $messenger
     * @param BroadcastDriver $broadcaster
     * @param DatabaseManager $database
     * @param PrivateThreadRepository $locator
     */
    public function __construct(Messenger $messenger,
                                BroadcastDriver $broadcaster,
                                DatabaseManager $database,
                                PrivateThreadRepository $locator)
    {
        $this->messenger = $messenger;
        $this->broadcaster = $broadcaster;
        $this->database = $database;
        $this->locator = $locator;
    }

    /**
     * Set the thread or provider we want to compose to. If a provider
     * is supplied, we will attempt to locate a private thread between
     * the "TO" and "FROM" providers. If no private thread is found,
     * one will be created. Please note that when a private thread
     * is created through this composer, friend status between the
     * two providers is ignored, and the thread will not be
     * marked pending.
     *
     * @param MessengerProvider|Thread $entity
     * @return $this
     * @throws MessengerComposerException
     */
    public function to($entity): self
    {
        if (! $entity instanceof Thread
            && ! $this->messenger->isValidMessengerProvider($entity)) {
            throw new MessengerComposerException('Invalid "TO" entity. Thread or messenger provider must be used.');
        }

        $this->to = $entity;

        return $this;
    }

    /**
     * Set the provider who is composing.
     *
     * @param MessengerProvider $provider
     * @return $this
     * @throws InvalidProviderException
     */
    public function from(MessengerProvider $provider): self
    {
        $this->messenger->setScopedProvider($provider);

        return $this;
    }

    /**
     * When sending our composed payload, silence any broadcast events.
     *
     * @return $this
     */
    public function silent(): self
    {
        $this->silent = true;

        return $this;
    }

    /**
     * Send a message. Optional reply to message ID and extra data allowed.
     *
     * @param string|null $message
     * @param string|null $replyingToId
     * @param array|null $extra
     * @return StoreMessage
     * @throws MessengerComposerException|Throwable
     */
    public function message(?string $message,
                            ?string $replyingToId = null,
                            ?array $extra = null): StoreMessage
    {
        $action = app(StoreMessage::class);

        $this->silenceActionWhenSilent($action);

        $action->execute($this->resolveThread(), [
            'message' => $message,
            'reply_to_id' => $replyingToId,
            'extra' => $extra,
        ]);

        $this->flush();

        return $action;
    }

    /**
     * Send an image message. Optional reply to message ID and extra data allowed.
     *
     * @param UploadedFile $image
     * @param string|null $replyingToId
     * @param array|null $extra
     * @return StoreImageMessage
     * @throws MessengerComposerException|Throwable
     */
    public function image(UploadedFile $image,
                          ?string $replyingToId = null,
                          ?array $extra = null): StoreImageMessage
    {
        $action = app(StoreImageMessage::class);

        $this->silenceActionWhenSilent($action);

        $action->execute($this->resolveThread(), [
            'image' => $image,
            'reply_to_id' => $replyingToId,
            'extra' => $extra,
        ]);

        $this->flush();

        return $action;
    }

    /**
     * Send a document message. Optional reply to message ID and extra data allowed.
     *
     * @param UploadedFile $document
     * @param string|null $replyingToId
     * @param array|null $extra
     * @return StoreDocumentMessage
     * @throws MessengerComposerException|Throwable
     */
    public function document(UploadedFile $document,
                             ?string $replyingToId = null,
                             ?array $extra = null): StoreDocumentMessage
    {
        $action = app(StoreDocumentMessage::class);

        $this->silenceActionWhenSilent($action);

        $action->execute($this->resolveThread(), [
            'document' => $document,
            'reply_to_id' => $replyingToId,
            'extra' => $extra,
        ]);

        $this->flush();

        return $action;
    }

    /**
     * Send an audio message. Optional reply to message ID and extra data allowed.
     *
     * @param UploadedFile $audio
     * @param string|null $replyingToId
     * @param array|null $extra
     * @return StoreAudioMessage
     * @throws MessengerComposerException|Throwable
     */
    public function audio(UploadedFile $audio,
                          ?string $replyingToId = null,
                          ?array $extra = null): StoreAudioMessage
    {
        $action = app(StoreAudioMessage::class);

        $this->silenceActionWhenSilent($action);

        $action->execute($this->resolveThread(), [
            'audio' => $audio,
            'reply_to_id' => $replyingToId,
            'extra' => $extra,
        ]);

        $this->flush();

        return $action;
    }

    /**
     * Add a reaction to the supplied message.
     *
     * @param Message $message
     * @param string $reaction
     * @return AddReaction
     * @throws MessengerComposerException|ReactionException
     * @throws Throwable|FeatureDisabledException
     */
    public function reaction(Message $message, string $reaction): AddReaction
    {
        $action = app(AddReaction::class);

        $this->silenceActionWhenSilent($action);

        $action->execute(
            $this->resolveThread(),
            $message,
            $reaction
        );

        $this->flush();

        return $action;
    }

    /**
     * Send a knock to the given thread.
     *
     * @return SendKnock
     * @throws FeatureDisabledException|KnockException
     * @throws MessengerComposerException|Throwable
     */
    public function knock(): SendKnock
    {
        $action = app(SendKnock::class);

        $action->execute($this->resolveThread());

        $this->flush();

        return $action;
    }

    /**
     * Mark the "FROM" provider or given participant as read.
     *
     * @param Participant|null $participant
     * @return MarkParticipantRead
     * @throws MessengerComposerException|Throwable
     */
    public function read(?Participant $participant = null): MarkParticipantRead
    {
        $action = app(MarkParticipantRead::class);

        $this->silenceActionWhenSilent($action);

        $action->execute(
            $participant ?: $this->resolveThread()->currentParticipant()
        );

        $this->flush();

        return $action;
    }

    /**
     * Emit a typing presence client event.
     *
     * @return $this
     * @throws MessengerComposerException
     */
    public function emitTyping(): self
    {
        $this->broadcaster
            ->toPresence($this->resolveThread())
            ->with(PresenceEvents::makeTypingEvent($this->messenger->getProvider()))
            ->broadcast(PresenceEvents::getTypingClass());

        return $this;
    }

    /**
     * Emit a stopped typing presence client event.
     *
     * @return $this
     * @throws MessengerComposerException
     */
    public function emitStopTyping(): self
    {
        $this->broadcaster
            ->toPresence($this->resolveThread())
            ->with(PresenceEvents::makeStopTypingEvent($this->messenger->getProvider()))
            ->broadcast(PresenceEvents::getStopTypingClass());

        return $this;
    }

    /**
     * Emit a read/seen presence client event.
     *
     * @return $this
     * @throws MessengerComposerException
     */
    public function emitRead(?Message $message = null): self
    {
        $this->broadcaster
            ->toPresence($this->resolveThread())
            ->with(PresenceEvents::makeReadEvent($this->messenger->getProvider(), $message))
            ->broadcast(PresenceEvents::getReadClass());

        return $this;
    }

    /**
     * @return $this
     */
    public function getInstance(): self
    {
        return $this;
    }

    /**
     * If TO is not a thread, resolve or create a private
     * thread between the TO and FROM providers. Set the
     * TO using the thread to avoid further queries when
     * reusing composer methods.
     *
     * @return Thread
     * @throws MessengerComposerException
     */
    private function resolveThread(): Thread
    {
        $this->bailIfNotReadyToCompose();

        if ($this->to instanceof Thread) {
            return $this->to;
        }

        $thread = $this->locator->getProviderPrivateThreadWithRecipient($this->to);

        return $this->to = is_null($thread)
            ? $this->makePrivateThread()
            : $thread;
    }

    /**
     * Check that we have TO and FROM set.
     *
     * @throws MessengerComposerException
     */
    private function bailIfNotReadyToCompose(): void
    {
        if (is_null($this->to)) {
            throw new MessengerComposerException('No "TO" entity has been set.');
        }

        if (! $this->messenger->isProviderSet()) {
            throw new MessengerComposerException('No "FROM" provider has been set.');
        }
    }

    /**
     * Make the private thread between the TO and FROM providers.
     *
     * @return Thread
     * @throws MessengerComposerException
     */
    private function makePrivateThread(): Thread
    {
        try {
            $this->database->transaction(function () {
                $thread = Thread::create(Thread::DefaultSettings);

                $thread->participants()->create(array_merge(Participant::DefaultPermissions, [
                    'owner_id' => $this->messenger->getProvider()->getKey(),
                    'owner_type' => $this->messenger->getProvider()->getMorphClass(),
                ]));

                $thread->participants()->create(array_merge(Participant::DefaultPermissions, [
                    'owner_id' => $this->to->getKey(),
                    'owner_type' => $this->to->getMorphClass(),
                ]));

                $this->to = $thread;
            });
        } catch (Throwable $e) {
            throw new MessengerComposerException('Storing new private failed with the message: '.$e->getMessage());
        }

        return $this->to;
    }

    /**
     * If silent was called, we will disable
     * broadcast from the action supplied.
     *
     * @param BaseMessengerAction $action
     */
    private function silenceActionWhenSilent(BaseMessengerAction $action): void
    {
        if ($this->silent) {
            $action->withoutBroadcast();
        }
    }

    /**
     * Reset our state and unset the scoped provider.
     *
     * @throws InvalidProviderException
     */
    private function flush(): void
    {
        $this->silent = false;
        $this->to = null;
        $this->messenger->unsetScopedProvider();
    }
}
