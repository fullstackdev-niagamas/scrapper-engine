<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Masuk · Ecommerce Scraper</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-gradient-to-br from-slate-50 via-slate-100 to-slate-200">
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="w-72 h-72 rounded-full bg-blue-200/40 blur-3xl absolute -top-10 -left-10"></div>
        <div class="w-96 h-96 rounded-full bg-emerald-200/40 blur-3xl absolute bottom-0 right-0"></div>
    </div>

    <div class="relative z-10 flex items-center justify-center min-h-screen px-4">
        <div class="w-full max-w-md">
            <div class="backdrop-blur-xl bg-white/70 shadow-xl ring-1 ring-black/5 rounded-2xl p-8">
                <div class="mb-6 text-center">
                    <div
                        class="mx-auto w-12 h-12 rounded-xl bg-emerald-600 flex items-center justify-center text-white font-bold">
                        ES</div>
                    <h1 class="mt-3 text-xl font-semibold text-slate-900">Masuk ke akun Anda</h1>
                    <p class="text-sm text-slate-600">Gunakan email dan kata sandi yang terdaftar</p>
                </div>

                @if ($errors->any())
                    <div class="mb-4 rounded-lg bg-red-50 text-red-700 text-sm p-3">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login.perform') }}" class="space-y-5">
                    @csrf

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}" required autofocus
                            spellcheck="false" class="w-full rounded-xl border border-slate-300 bg-white/90 px-4 py-3 text-slate-900 placeholder-slate-400 shadow-sm
             focus:outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/20"
                            placeholder="example@mail.com">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Password</label>
                        <input type="password" name="password" required class="w-full rounded-xl border border-slate-300 bg-white/90 px-4 py-3 text-slate-900 placeholder-slate-400 shadow-sm
             focus:outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/20"
                            placeholder="••••••••">
                    </div>

                    <div class="flex items-center justify-between">
                        <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" name="remember"
                                class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                            Ingat saya
                        </label>
                    </div>

                    <button type="submit" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 text-white py-3 font-medium
                 hover:bg-emerald-700 transition shadow-sm">
                        Masuk
                    </button>
                </form>

                <form method="POST" action="{{ route('logout') }}" class="hidden">@csrf</form>
            </div>

            <p class="text-center text-xs text-slate-500 mt-6">
                © {{ date('Y') }} Ecommerce Scraper
            </p>
        </div>
    </div>
</body>

</html>