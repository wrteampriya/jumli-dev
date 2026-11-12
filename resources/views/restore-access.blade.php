<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restore License Access</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --bg: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.7);
            --text-main: #f8fafc;
            --text-dim: #94a3b8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg);
            background-image:
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(168, 85, 247, 0.15) 0px, transparent 50%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            overflow: hidden;
        }

        .glass-container {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 60px 40px;
            max-width: 500px;
            width: 90%;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .icon { font-size: 56px; margin-bottom: 16px; }

        h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--text-main);
        }

        .message {
            font-size: 16px;
            color: var(--text-dim);
            line-height: 1.6;
            margin-bottom: 32px;
        }

        .recovery-input {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            font-family: 'Outfit', sans-serif;
            font-size: 15px;
            margin-bottom: 12px;
            outline: none;
            transition: border-color 0.2s;
        }

        .recovery-input:focus { border-color: var(--primary); }
        .recovery-input.input-error { border-color: #f87171; }

        .error-text {
            color: #f87171;
            font-size: 13px;
            margin-bottom: 16px;
            text-align: left;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
            width: 100%;
            justify-content: center;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
        }

        .blob {
            position: absolute;
            width: 300px;
            height: 300px;
            background: rgba(99, 102, 241, 0.1);
            filter: blur(80px);
            border-radius: 50%;
            z-index: -1;
        }
    </style>
</head>
<body>
    <div class="blob" style="top: -100px; left: -100px;"></div>
    <div class="blob" style="bottom: -100px; right: -100px;"></div>

    <div class="glass-container">
        <div class="icon">🔑</div>
        <h1>License Key Required</h1>

        <p class="message">
            Your purchase code is not configured. Enter it below to restore access.
        </p>

        @if ($errors->has('purchase_code'))
            <p class="error-text">{{ $errors->first('purchase_code') }}</p>
        @endif

        <form method="POST" action="{{ route('restore.access') }}">
            @csrf
            <input
                type="text"
                name="purchase_code"
                class="recovery-input {{ $errors->has('purchase_code') ? 'input-error' : '' }}"
                placeholder="Enter your Envato purchase code"
                value="{{ old('purchase_code') }}"
                required
            />
            <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 10px;">
                <button type="submit" class="btn btn-primary">Restore Access</button>
                <a href="/" class="btn btn-outline" style="width: 100%; border: 1px solid rgba(255,255,255,0.1); color: var(--text-dim); text-decoration: none; display: flex; align-items: center; justify-content: center; padding: 12px; border-radius: 12px; font-size: 14px; transition: 0.2s;">
                    Cancel & Go Back
                </a>
            </div>
        </form>
    </div>
</body>
</html>
