<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Music Player Grid</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&icon_names=pause_circle" />

    <style>
        .glass {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(12px) saturate(160%);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .control-btn {
            width: 52px;
            height: 52px;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #15030d, #5db9ff);
            color: #000;
            font-size: 1.4rem;
            font-weight: bold;
            transition: transform .15s;
        }
        .control-btn:hover {
            transform: scale(1.08);
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-900 via-black to-gray-800 p-10 text-white">

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-10 max-w-6xl mx-auto">

    <!-- TEMPLATE FOR 5 PLAYERS -->
    <!-- Player 1 -->
    <div class="flex items-center space-x-6 p-6 bg-gray-900 rounded-xl shadow-lg">
        <img src="/mnt/data/Muziekspeler met System of a Down.png" alt="Album Cover" class="w-40 h-40 rounded-lg object-cover" />
        <div class="flex flex-col space-y-3 w-72">
            <div>
                <h1 class="text-3xl font-semibold">Aerials</h1>
                <p class="text-gray-300 text-lg">System of a Down</p>
                <p class="text-gray-500">Toxicity</p>
            </div>
            <div>
                <div class="w-full h-1 bg-gray-700 rounded-full">
                    <div class="h-1 bg-white rounded-full" style="width:45%"></div>
                </div>
                <div class="flex justify-between text-sm text-gray-400 mt-1">
                    <span>1:35</span>
                    <span>3:40</span>
                </div>
            </div>
            <div class="flex justify-center space-x-6 mt-2">
                <button class="material-icons text-4xl text-gray-300 hover:text-white">skip_previous</button>
                <button class="material-icons text-5xl text-white hover:text-gray-300">play_arrow</button>
                <button class="material-icons text-4xl text-gray-300 hover:text-white">skip_next</button>
            </div>
        </div>
    </div>


</div>

<script>
    function togglePlay(id) {
        const p = document.getElementById(id);
        if (p.paused) p.play(); else p.pause();
    }
</script>

</body>
</html>
