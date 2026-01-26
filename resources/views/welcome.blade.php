<!DOCTYPE html>
<html lang="en" class="bg-gray-50">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>B&O Devices • Light Mode</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=SF+Pro+Display:wght@300;400;500;600;700&display=swap');
        body { font-family: 'SF Pro Display', system-ui, -apple-system, sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 text-gray-900  dark:text-gray-300 dark:bg-stone-950  dark:from-stone-950 dark:to-stone-80 antialiased">

<div class="flex min-h-screen">

    <!-- Sidebar Navigation -->
{{--    <aside class="hidden md:flex flex-col w-72 bg-white border-r border-gray-200 shadow-sm">--}}
{{--        <div class="p-8 border-b border-gray-200">--}}
{{--            <div class="flex items-center gap-3">--}}
{{--                <div class="text-2xl font-medium tracking-tight text-gray-900">B&O</div>--}}
{{--                <div class="text-base text-gray-400">×</div>--}}
{{--                <div class="text-2xl font-light text-red-600">Music</div>--}}
{{--            </div>--}}
{{--        </div>--}}

{{--        <nav class="flex-1 px-4 py-6">--}}
{{--            <ul class="space-y-1">--}}
{{--                <li>--}}
{{--                    <a href="#" class="flex items-center gap-3 px-5 py-3.5 bg-gray-100 rounded-xl font-medium text-gray-900">--}}
{{--                        <i class="fa-solid fa-tv w-5 text-gray-700"></i>--}}
{{--                        Devices--}}
{{--                    </a>--}}
{{--                </li>--}}
{{--                <li>--}}
{{--                    <a href="#" class="flex items-center gap-3 px-5 py-3.5 text-gray-600 hover:bg-gray-50 rounded-xl transition-colors">--}}
{{--                        <i class="fa-solid fa-music w-5"></i>--}}
{{--                        Media--}}
{{--                    </a>--}}
{{--                </li>--}}
{{--                <li>--}}
{{--                    <a href="#" class="flex items-center gap-3 px-5 py-3.5 text-gray-600 hover:bg-gray-50 rounded-xl transition-colors">--}}
{{--                        <i class="fa-solid fa-clock-rotate-left w-5"></i>--}}
{{--                        History--}}
{{--                    </a>--}}
{{--                </li>--}}
{{--                <li>--}}
{{--                    <a href="#" class="flex items-center gap-3 px-5 py-3.5 text-gray-600 hover:bg-gray-50 rounded-xl transition-colors">--}}
{{--                        <i class="fa-solid fa-sliders w-5"></i>--}}
{{--                        Configuration--}}
{{--                    </a>--}}
{{--                </li>--}}
{{--            </ul>--}}
{{--        </nav>--}}

{{--        <div class="p-6 border-t border-gray-200 text-sm text-gray-500">--}}
{{--            v2.4.1 • Jan 11, 2026--}}
{{--        </div>--}}
{{--    </aside>--}}

    <!-- Main Content -->
    <main class="flex-1 pt-5 pb-12 px-5 sm:px-8 lg:px-12">

        <!-- Mobile top bar -->
        <div class="md:hidden flex items-center justify-between mb-8 bg-white/80 backdrop-blur-lg p-4 -mx-5 -mt-5 border-b border-gray-200 sticky top-0 z-10">
            <div class="text-xl font-medium">Devices</div>
            <button class="text-gray-600">
                <i class="fa-solid fa-bars text-xl"></i>
            </button>
        </div>

        <div class="mb-10">
            <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Devices</h1>
            <p class="mt-1.5 text-gray-500">January 11, 2026 — 16:42</p>
        </div>

        <div class="grid gap-7 md:grid-cols-2 lg:grid-cols-3">

            <!-- Now Playing – Beoplayer (Music) -->
            <div class="md:col-span-2 bg-white rounded-3xl shadow-xl border border-gray-200/80 dark:border-stone-900/80 overflow-hidden hover:shadow-2xl dark:bg-stone-900 transition-all">
                <div class="p-8 md:p-10">
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center gap-3">
                            <div class="relative">
                                <div class="w-4 h-4 rounded-full bg-emerald-500 animate-pulse ring-4 ring-emerald-400/20"></div>
                            </div>
                            <span class="text-base font-medium uppercase tracking-wider text-emerald-600 dark:text-emerald-500">Now Playing</span>
                        </div>
                        <span class="text-sm text-gray-600 dark:text-gray-400">Beoplayer M5 2 • Living Room</span>
                    </div>

                    <div class="flex flex-col lg:flex-row gap-8">
                        <div class="w-full lg:w-64 aspect-square rounded-2xl overflow-hidden shadow-lg ring-1 ring-gray-200 dark:ring-stone-900">
                            <img src="https://images.unsplash.com/photo-1611339555312-e607c8352fd7?w=800" alt="Album" class="w-full h-full object-cover">
                        </div>

                        <div class="flex-1 space-y-6">
                            <div>
                                <h2 class="text-3xl lg:text-4xl dark:text-emerald-50 font-medium tracking-tight">From the Inside</h2>
                                <p class="mt-2 text-xl text-gray-600 dark:text-gray-400">Linkin Park • Meteora (Bonus Edition)</p>
                            </div>

                            <div class="space-y-6">
                                <div>
                                    <div class="flex justify-between text-sm text-gray-500 mb-2">
                                        <span>2:32</span><span>2:55</span>
                                    </div>
                                    <div class="h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                        <div class="h-full w-[87%] bg-gradient-to-r from-red-500 to-rose-600 rounded-full"></div>
                                    </div>
                                </div>

                                <div class="flex items-center justify-center lg:justify-start gap-10">
                                    <button class="text-gray-400 dark:text-gray-300 hover:text-gray-800 text-2xl"><i class="fa-solid fa-backward-step"></i></button>
                                    <button class="text-gray-900 dark:text-gray-200 hover:scale-110 transition-transform"><i class="fa-solid fa-pause-circle text-6xl drop-shadow"></i></button>
                                    <button class="text-gray-400 dark:text-gray-300 hover:text-gray-800 text-2xl"><i class="fa-solid fa-forward-step"></i></button>
                                </div>

                                <div class="flex items-center gap-4 max-w-md">
                                    <i class="fa-solid fa-volume-high text-gray-500 w-5"></i>
                                    <div class="flex-1 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                        <div class="h-full w-[45%] bg-gray-700 dark:bg-stone-600 rounded-full"></div>
                                    </div>
                                    <span class="text-sm font-medium text-gray-700 w-8">45</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TV Device Example -->
            <div class="bg-white rounded-3xl shadow-lg border border-gray-200/70 p-8 hover:shadow-xl dark:bg-stone-900 dark:border-stone-900/80 transition-all">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-3">
                        <div class="w-3.5 h-3.5 rounded-full bg-blue-500 dark:bg-blue-700"></div>
                        <h3 class="text-xl font-medium">Beovision Contour 65</h3>
                    </div>
                    <span class="text-sm uppercase tracking-wider text-gray-500">Active</span>
                </div>

                <div class="h-48 bg-gray-900 dark:bg-gray-600 rounded-2xl mb-6 flex items-center justify-center text-white/70 text-xl font-medium">
                    HDMI 2 • Apple TV 4K
                </div>

                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Input</span>
                        <span class="font-medium text-gray-800">HDMI 2</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Last active</span>
                        <span class="text-gray-800">Today 15:40</span>
                    </div>
                </div>
            </div>

            <!-- Radio Device Example -->
            <div class="bg-white rounded-3xl shadow-lg border border-gray-200/70 p-8 hover:shadow-xl transition-all">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-3.5 h-3.5 rounded-full bg-amber-500"></div>
                    <h3 class="text-xl font-medium">Beosound A5 • Radio</h3>
                </div>

                <div class="h-48 bg-gradient-to-br from-amber-50 to-amber-100 rounded-2xl mb-6 flex flex-col items-center justify-center text-center px-6">
                    <div class="text-2xl font-medium text-amber-900">NPO Radio 2</div>
                    <div class="mt-2 text-gray-700">The Weeknd – Blinding Lights</div>
                </div>

                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Station</span>
                        <span class="font-medium text-gray-800">NPO Radio 2</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Volume</span>
                        <span class="font-medium text-gray-800">34</span>
                    </div>
                </div>
            </div>

            <!-- Classic standby speaker -->
            <div class="bg-white rounded-3xl shadow-lg border border-gray-200/70 p-8 hover:shadow-xl transition-all">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-3">
                        <div class="w-3.5 h-3.5 rounded-full bg-red-500"></div>
                        <h3 class="text-xl font-medium">Woonkamer Speakers 1</h3>
                    </div>
                    <span class="text-sm uppercase tracking-wider text-gray-500">Standby</span>
                </div>

                <div class="h-48 bg-gray-100 rounded-2xl flex items-center justify-center text-gray-400 mb-6">
                    <i class="fa-solid fa-power-off text-6xl opacity-25"></i>
                </div>

                <div class="flex items-center gap-3 text-sm">
                    <span class="text-gray-600">Volume</span>
                    <div class="flex-1 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                        <div class="h-full w-[28%] bg-gray-400 rounded-full"></div>
                    </div>
                    <span class="font-medium text-gray-700">28</span>
                </div>
            </div>

        </div>
    </main>
</div>
</body>
</html>
