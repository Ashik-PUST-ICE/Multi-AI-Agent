<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Authorize CLI device - {{ config('app.name') }}</title>
    <style>
        body { background: #f3f4f6; color: #111827; font: 16px system-ui, sans-serif; margin: 0; padding: 2rem 1rem; }
        main { background: white; border-radius: 1rem; margin: 4vh auto; max-width: 32rem; padding: 2rem; box-shadow: 0 12px 40px #11182718; }
        h1 { margin-top: 0; } label { display: block; font-weight: 600; margin: 1rem 0 .35rem; }
        input { border: 1px solid #9ca3af; border-radius: .5rem; box-sizing: border-box; font: inherit; padding: .7rem; width: 100%; }
        button { border: 0; border-radius: .5rem; cursor: pointer; font: inherit; font-weight: 700; margin: .6rem .4rem 0 0; padding: .7rem 1rem; }
        .approve { background: #2563eb; color: white; } .deny { background: #e5e7eb; color: #111827; }
        .notice { background: #eff6ff; border-radius: .5rem; padding: .8rem; }
        .error { color: #b91c1c; } .code { font: 700 1.4rem ui-monospace, monospace; letter-spacing: .12em; }
    </style>
</head>
<body>
<main>
    <h1>Authorize CLI device</h1>
    <p>Verify that this code matches the one shown in your CLI. Your password is entered only on this page and is never sent to the CLI.</p>
    <p class="notice" id="device-message" role="status" hidden></p>
    <p class="error" id="device-error" role="alert" hidden></p>
    <form id="device-form" data-api-url="{{ url('/api/v1/auth/device') }}">
        <label for="user_code">Device code shown in your CLI</label>
        <input id="user_code" name="user_code" value="{{ $userCode }}" maxlength="9" autocomplete="off" required>
        <label for="email">Account email</label>
        <input id="email" name="email" type="email" autocomplete="username" required>
        <label for="password">Account password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <label><input name="confirm" type="checkbox" value="1" required style="width:auto"> I approve this CLI device sign-in.</label>
        <button class="approve" type="submit">Authorize device</button>
        <button class="deny" type="button" id="deny-device">Deny</button>
    </form>
    <script>
        const form = document.getElementById('device-form');
        const message = document.getElementById('device-message');
        const error = document.getElementById('device-error');
        const apiUrl = form.dataset.apiUrl;
        async function sendDecision(path, data) {
            message.hidden = true;
            error.hidden = true;
            try {
                const response = await fetch(apiUrl + '/' + path, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();
                if (!response.ok) {
                    error.textContent = result.message || 'The device request could not be completed.';
                    error.hidden = false;
                    return;
                }
                message.textContent = result.message || 'Device request updated.';
                message.hidden = false;
                form.querySelectorAll('input, button').forEach((input) => { input.disabled = true; });
            } catch {
                error.textContent = 'Could not reach the API. Check your connection and try again.';
                error.hidden = false;
            }
        }
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            const values = Object.fromEntries(new FormData(form).entries());
            sendDecision('approve', values);
        });
        document.getElementById('deny-device').addEventListener('click', () => {
            if (!document.getElementById('user_code').reportValidity()) return;
            sendDecision('deny', { user_code: document.getElementById('user_code').value });
        });
    </script>
</main>
</body>
</html>
