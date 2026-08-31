# Команды запуска парсера

| Команда | Описание |
|---|---|
| `composer parse` | Запуск парсера через Composer-скрипт |
| `composer parse-debug` | Запуск парсера через Composer-скрипт с выводом логов |
| `php index.php` | Запуск парсера с параметрами из `.env` |
| `php index.php --logs` | Запуск парсера с выводом логов |
| `php index.php --parser="reshak" --output="output" --start-urls="https://reshak.ru/tag/4klass.html" --attempts=5 --timeout=5 --logs` | Запуск парсера с указанием всех основных параметров через CLI |
| `php index.php --start-urls="https://reshak.ru/tag/4klass.html" --proxy="96.62.194.189:6391:user:pass,31.98.15.181:5358:user:pass" --attempts=5 --timeout=5 --logs` | Запуск парсера со стартовой ссылкой, прокси, количеством попыток, таймаутом и логами |

# Параметры команд

| Параметр | Описание |
|---|---|
| `--parser="reshak"` | Указывает тип парсера. Сейчас доступен парсер `reshak` |
| `--output="output"` | Указывает папку для сохранения результатов парсинга |
| `--start-urls="https://reshak.ru/tag/4klass.html"` | Указывает стартовую ссылку для парсинга |
| `--start-urls="url1,url2"` | Указывает несколько стартовых ссылок через запятую |
| `--proxy="host:port:user:pass"` | Указывает прокси-сервер |
| `--proxy="host1:port:user:pass,host2:port:user:pass"` | Указывает несколько прокси через запятую |
| `--attempts=5` | Указывает количество попыток запроса |
| `--timeout=5` | Указывает таймаут запроса в секундах |
| `--logs` | Включает вывод логов в консоль |