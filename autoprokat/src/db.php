<?php
require __DIR__ . '/config.php';

function fail(string $msg) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fail('Не удалось подключиться к MySQL. Запустите MySQL в XAMPP Control Panel (кнопка Start). ' . $e->getMessage());
}

try {
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . DB_NAME . '`');

    if (!$pdo->query("SHOW TABLES LIKE 'users'")->fetch()) {
        $pdo->exec("CREATE TABLE users(
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('user','admin') NOT NULL DEFAULT 'user',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE cars(
            id INT AUTO_INCREMENT PRIMARY KEY,
            brand VARCHAR(60) NOT NULL,
            model VARCHAR(60) NOT NULL,
            year INT NOT NULL,
            category VARCHAR(20) NOT NULL,
            price_per_day DOUBLE NOT NULL,
            seats INT NOT NULL DEFAULT 5,
            transmission VARCHAR(2) NOT NULL DEFAULT 'AT',
            description VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_category(category),
            INDEX idx_price(price_per_day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE bookings(
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            car_id INT NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            total DOUBLE NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_car_dates(car_id, start_date, end_date),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->prepare("INSERT INTO users(name,email,password_hash,role) VALUES('Администратор','admin@rent.kz',?,'admin')")
            ->execute([password_hash('Admin123!', PASSWORD_DEFAULT)]);
        $s = $pdo->prepare('INSERT INTO cars(brand,model,year,category,price_per_day,seats,transmission,description) VALUES(?,?,?,?,?,?,?,?)');
        foreach ([
            ['Toyota', 'Camry', 2022, 'business', 28000, 5, 'AT', 'Комфортный седан бизнес-класса'],
            ['Hyundai', 'Solaris', 2021, 'economy', 12000, 5, 'AT', 'Экономичный городской автомобиль'],
            ['Kia', 'Rio', 2020, 'economy', 11000, 5, 'MT', 'Надёжный и недорогой'],
            ['BMW', 'X5', 2023, 'premium', 65000, 5, 'AT', 'Премиальный кроссовер'],
            ['Toyota', 'Land Cruiser 300', 2023, 'suv', 80000, 7, 'AT', 'Внедорожник для любых дорог'],
            ['Chevrolet', 'Tahoe', 2021, 'suv', 55000, 7, 'AT', 'Просторный полноразмерный SUV'],
            ['Mercedes', 'E-Class', 2022, 'premium', 60000, 5, 'AT', 'Бизнес-седан премиум-класса'],
            ['Skoda', 'Octavia', 2022, 'economy', 15000, 5, 'AT', 'Практичный лифтбек'],
        ] as $c) $s->execute($c);
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS favorites(
        user_id INT NOT NULL, car_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(user_id, car_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reviews(
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, car_id INT NOT NULL,
        rating TINYINT NOT NULL, comment VARCHAR(500) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_car(user_id, car_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS meta(k VARCHAR(50) PRIMARY KEY, v VARCHAR(50)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$pdo->query("SELECT 1 FROM meta WHERE k='seed_v4'")->fetch()) {
        $ex = $pdo->prepare('SELECT 1 FROM cars WHERE brand=? AND model=?');
        $in = $pdo->prepare('INSERT INTO cars(brand,model,year,category,price_per_day,seats,transmission,description) VALUES(?,?,?,?,?,?,?,?)');
        foreach ([
            ['Chevrolet','Cobalt',2021,'economy',9500,5,'AT','Простой и надёжный, расход около 7 л'],
            ['Lada','Vesta',2022,'economy',10000,5,'MT','Недорогой седан для города и трассы'],
            ['Renault','Logan',2020,'economy',9000,5,'MT','Большой багажник за небольшие деньги'],
            ['Hyundai','Accent',2022,'economy',13000,5,'AT','Комфортный городской седан'],
            ['Volkswagen','Polo',2021,'economy',14000,5,'AT','Аккуратный и экономичный'],
            ['Nissan','Almera',2021,'economy',12500,5,'AT','Просторный салон для своего класса'],
            ['Kia','K5',2023,'business',30000,5,'AT','Современный седан с богатой комплектацией'],
            ['Hyundai','Sonata',2022,'business',27000,5,'AT','Тихий и мягкий на ходу'],
            ['Toyota','Corolla',2023,'business',20000,5,'AT','Проверенная надёжность Toyota'],
            ['Volkswagen','Passat',2021,'business',26000,5,'AT','Просторный седан для длинных поездок'],
            ['Skoda','Superb',2022,'business',29000,5,'AT','Огромный салон и багажник'],
            ['Audi','A6',2022,'premium',58000,5,'AT','Динамика и комфорт Audi'],
            ['Lexus','ES',2023,'premium',55000,5,'AT','Тишина и плавность хода'],
            ['Mercedes','S-Class',2023,'premium',120000,5,'AT','Флагман для особых случаев'],
            ['BMW','5 Series',2022,'premium',62000,5,'AT','Спортивный характер, бизнес-комфорт'],
            ['Porsche','Macan',2022,'premium',90000,5,'AT','Спортивный кроссовер'],
            ['Toyota','RAV4',2023,'suv',32000,5,'AT','Универсальный кроссовер'],
            ['Hyundai','Tucson',2023,'suv',29000,5,'AT','Современный дизайн и просторный салон'],
            ['Kia','Sportage',2023,'suv',29500,5,'AT','Комфорт для семьи и дальних поездок'],
            ['Toyota','Land Cruiser Prado',2022,'suv',60000,7,'AT','Проходимость для любых дорог'],
            ['Nissan','X-Trail',2022,'suv',28000,5,'AT','Семейный кроссовер'],
            ['Lexus','LX 600',2023,'suv',130000,7,'AT','Роскошный внедорожник'],
            ['Chery','Tiggo 7 Pro',2023,'suv',22000,5,'AT','Много оснащения за разумную цену'],
            ['Haval','Jolion',2023,'suv',21000,5,'AT','Компактный и выгодный кроссовер'],
            ['Toyota','Alphard',2022,'minivan',75000,7,'AT','Минивэн для поездок с комфортом'],
            ['Hyundai','Staria',2023,'minivan',50000,9,'AT','Девять мест для большой компании'],
            ['Kia','Carnival',2022,'minivan',45000,7,'AT','Просторный семейный минивэн'],
            ['Mercedes','V-Class',2022,'minivan',85000,7,'AT','Трансфер и поездки бизнес-класса'],
            ['Tesla','Model 3',2022,'electric',45000,5,'AT','Электромобиль, около 500 км запаса хода'],
            ['Tesla','Model Y',2023,'electric',52000,5,'AT','Электрокроссовер с большим багажником'],
            ['BYD','Han',2023,'electric',38000,5,'AT','Электроседан с быстрой зарядкой'],
            ['Zeekr','001',2023,'electric',48000,5,'AT','Быстрый электро лифтбек'],
            ['Hyundai','Ioniq 5',2023,'electric',42000,5,'AT','Электрокроссовер с просторным салоном'],
        ] as $c) { $ex->execute([$c[0], $c[1]]); if (!$ex->fetch()) $in->execute($c); }
        $pdo->exec("INSERT INTO meta(k,v) VALUES('seed_v4','1')");
    }
} catch (PDOException $e) {
    fail('Ошибка базы данных: ' . $e->getMessage());
}
