<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Feedback</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f5f6f8; color: #1f2430; line-height: 1.5; }
        main { max-width: 680px; margin: 0 auto; padding: 32px 16px 48px; }
        .card { background: #fff; border: 1px solid #e3e6ec; border-radius: 12px; padding: 24px; }
        h1 { font-size: 1.35rem; margin: 0 0 8px; }
        p.muted { color: #5b6273; margin: 0 0 16px; }
        fieldset { border: 0; padding: 0; margin: 0 0 20px; }
        legend { font-weight: 600; margin-bottom: 8px; }
        label.option { display: inline-flex; align-items: center; gap: 6px; margin: 0 14px 8px 0; }
        textarea, input[type=number], input[type=date] { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #cfd4dd; border-radius: 8px; font: inherit; }
        textarea { min-height: 96px; }
        .error { background: #fdecec; color: #8a1c1c; border: 1px solid #f3c2c2; border-radius: 8px; padding: 10px 12px; margin-bottom: 16px; }
        .actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px; }
        button { font: inherit; border-radius: 8px; padding: 10px 18px; border: 1px solid transparent; cursor: pointer; }
        button.primary { background: #3b3fd8; color: #fff; }
        button.secondary { background: #fff; color: #1f2430; border-color: #cfd4dd; }
        .req { color: #b42318; }
    </style>
</head>
<body>
<main>
    <div class="card">
        @if ($state === 'form')
            <h1>Feedback{{ $about ? ' about '.$about : '' }}</h1>
            <p class="muted">{{ $organisation }} asked you{{ $name ? ', '.$name.',' : '' }} for your feedback. It is due by {{ $due }}; this link works once.</p>
            @if ($error)
                <div class="error" role="alert">{{ $error }}</div>
            @endif
            <form method="post" action="{{ $action }}">
                @csrf
                @foreach ($questions as $q)
                    @php($key = $q['key'])
                    @php($prev = $old[$key] ?? null)
                    <fieldset>
                        <legend>{{ $q['title'] }} @if ($q['mandatory'] ?? false)<span class="req" aria-label="required">*</span>@endif</legend>
                        @switch($q['type'])
                            @case('single_choice')
                                @foreach ($q['options'] ?? [] as $option)
                                    <label class="option"><input type="radio" name="answers[{{ $key }}]" value="{{ $option['value'] }}" @checked($prev === $option['value'])> {{ $option['label'] }}</label>
                                @endforeach
                                @break
                            @case('multiple_choice')
                                @foreach ($q['options'] ?? [] as $option)
                                    <label class="option"><input type="checkbox" name="answers[{{ $key }}][]" value="{{ $option['value'] }}" @checked(in_array($option['value'], (array) $prev, true))> {{ $option['label'] }}</label>
                                @endforeach
                                @break
                            @case('rating')
                                @for ($i = 1; $i <= (int) ($q['scale_max'] ?? 5); $i++)
                                    <label class="option"><input type="radio" name="answers[{{ $key }}]" value="{{ $i }}" @checked((string) $prev === (string) $i)> {{ $i }}</label>
                                @endfor
                                @break
                            @case('likert')
                                @foreach (config('peopleos.engagement.likert_options', []) as $value => $label)
                                    <label class="option"><input type="radio" name="answers[{{ $key }}]" value="{{ $value }}" @checked((string) $prev === (string) $value)> {{ $label }}</label>
                                @endforeach
                                @break
                            @case('yes_no')
                                <label class="option"><input type="radio" name="answers[{{ $key }}]" value="yes" @checked($prev === 'yes')> Yes</label>
                                <label class="option"><input type="radio" name="answers[{{ $key }}]" value="no" @checked($prev === 'no')> No</label>
                                @break
                            @case('number')
                                <input type="number" step="any" name="answers[{{ $key }}]" value="{{ is_scalar($prev) ? $prev : '' }}" aria-label="{{ $q['title'] }}">
                                @break
                            @case('date')
                                <input type="date" name="answers[{{ $key }}]" value="{{ is_scalar($prev) ? $prev : '' }}" aria-label="{{ $q['title'] }}">
                                @break
                            @default
                                <textarea name="answers[{{ $key }}]" maxlength="5000" aria-label="{{ $q['title'] }}">{{ is_scalar($prev) ? $prev : '' }}</textarea>
                        @endswitch
                    </fieldset>
                @endforeach
                <div class="actions">
                    <button type="submit" class="primary">Send feedback</button>
                    <button type="submit" class="secondary" name="decline" value="1" formnovalidate>I prefer not to take part</button>
                </div>
            </form>
        @elseif ($state === 'thanks')
            <h1>Thank you</h1>
            <p class="muted">Your feedback was received. You can close this page.</p>
        @elseif ($state === 'declined')
            <h1>Noted</h1>
            <p class="muted">You chose not to take part. You can close this page.</p>
        @else
            <h1>This link is not available</h1>
            <p class="muted">It has already been used, it has expired, or the feedback is no longer needed.</p>
        @endif
    </div>
</main>
</body>
</html>
