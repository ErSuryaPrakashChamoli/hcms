<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Models\NotificationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/** Event -> matching rules -> audience -> channels -> template -> Notifier. */
final class NotificationEngine
{
    public function __construct(
        private readonly RuleEngine $rules,
        private readonly AudienceResolver $audience,
        private readonly TemplateRenderer $renderer,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $context  from NotificationContext::build()
     * @return Collection<int, NotificationDelivery>
     */
    public function fire(string $event, array $context, ?Model $source = null): Collection
    {
        $sent = Collection::make();
        $flat = Arr::dot(array_filter($context, fn ($v) => $v !== null));

        NotificationRule::query()
            ->with('template')
            ->where('event', $event)
            ->where('status', 'active')
            ->whereHas('template', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->each(function (NotificationRule $rule) use ($context, $flat, $event, $source, &$sent) {
                if (! $this->rules->matches($rule->conditions ?? [], $flat, 'all')) {
                    return;
                }

                $users = $this->audience->resolve($rule->audience ?? [], $context);

                if ($users->isEmpty()) {
                    return;
                }

                $sent = $sent->merge($this->notifier->send(
                    $users,
                    $rule->channels ?? ['in_app'],
                    $this->renderer->render($rule->template->subject, $context),
                    $this->renderer->render($rule->template->body, $context),
                    $event,
                    $source,
                ));
            });

        return $sent;
    }
}
