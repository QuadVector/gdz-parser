# Команды запуска парсера

| Команда | Описание |
|---|---|
| `composer parse` | Запуск парсера через Composer-скрипт |
| `composer parse-debug` | Запуск парсера через Composer-скрипт с выводом логов |
| `php index.php` | Запуск парсера с параметрами из `.env` |
| `php index.php --logs` | Запуск парсера с выводом логов |
| `php index.php --mode="books" --logs` | Парсинг структуры до книг включительно |
| `php index.php --mode="tasks" --logs` | Парсинг структуры до списка задач включительно |
| `php index.php --mode="all" --logs` | Полный парсинг книг и задач |
| `php index.php --mode="all" --parse-images --logs` | Полный парсинг с обработкой изображений |
| `php index.php --parser="reshak" --output="output" --start-urls="https://reshak.ru/tag/4klass.html" --mode="all" --attempts=5 --timeout=5 --logs` | Запуск парсера с указанием основных параметров через CLI |
| `php index.php --start-urls="https://reshak.ru/tag/4klass.html" --proxy="96.62.194.189:6391:user:pass,31.98.15.181:5358:user:pass" --mode="all" --attempts=5 --timeout=5 --parse-images --logs` | Запуск парсера со стартовой ссылкой, прокси, полным режимом парсинга, изображениями и логами |

# Параметры команд

| Параметр | Описание |
|---|---|
| `--parser="reshak"` | Указывает тип парсера. Сейчас доступен парсер `reshak` |
| `--output="output"` | Указывает папку для сохранения результатов парсинга |
| `--start-urls="https://reshak.ru/tag/4klass.html"` | Указывает одну стартовую ссылку для парсинга |
| `--start-urls="url1,url2"` | Указывает несколько стартовых ссылок через запятую |
| `--proxy="host:port:user:pass"` | Указывает один прокси-сервер |
| `--proxy="host1:port:user:pass,host2:port:user:pass"` | Указывает несколько прокси-серверов через запятую |
| `--attempts=5` | Указывает максимальное количество попыток выполнения запроса |
| `--timeout=5` | Указывает таймаут HTTP-запроса в секундах |
| `--mode="books"` | Парсинг до уровня книг включительно. Задачи не парсятся |
| `--mode="tasks"` | Парсинг до получения списка задач включительно, без полного парсинга содержимого задач |
| `--mode="all"` | Полный парсинг: книги, структура и содержимое задач |
| `--parse-images` | Включает парсинг и обработку изображений |
| `--logs` | Включает вывод логов в консоль |

# Режимы парсинга

Парсер поддерживает несколько режимов работы через параметр `--mode`.

## `books`

```bash
php index.php --mode="books" --logs
```

Парсинг выполняется **до книг включительно**.

Подходит, если необходимо получить структуру доступных книг без дальнейшего обхода задач.

## `tasks`

```bash
php index.php --mode="tasks" --logs
```

Парсинг выполняется **до списка задач включительно**.

Парсер получает книги и связанные с ними задачи, но не выполняет полный парсинг содержимого каждой задачи.

## `all`

```bash
php index.php --mode="all" --logs
```

Выполняется **полный парсинг**.

Парсер последовательно получает книги, структуру задач и полностью обрабатывает сами задачи.

# Парсинг изображений

Для включения обработки изображений используется флаг:

```bash
--parse-images
```

Например:

```bash
php index.php --mode="all" --parse-images --logs
```

`--parse-images` является флагом и не требует значения.

Правильно:

```bash
--parse-images
```

Передавать значение не требуется:

```bash
--parse-images=true
```

# Несколько стартовых URL

Несколько стартовых страниц можно передать через запятую:

```bash
php index.php \
    --start-urls="https://reshak.ru/tag/4klass.html,https://reshak.ru/tag/5klass.html" \
    --mode="all" \
    --logs
```

# Использование прокси

Один прокси:

```bash
php index.php \
    --proxy="96.62.194.189:6391:user:pass" \
    --mode="all" \
    --logs
```

Несколько прокси:

```bash
php index.php \
    --proxy="96.62.194.189:6391:user:pass,31.98.15.181:5358:user:pass" \
    --mode="all" \
    --logs
```

Формат прокси:

```text
host:port:user:password
```

# Полный пример запуска

```bash
php index.php \
    --parser="reshak" \
    --output="output" \
    --start-urls="https://reshak.ru/tag/4klass.html,https://reshak.ru/tag/5klass.html" \
    --proxy="96.62.194.189:6391:user:pass,31.98.15.181:5358:user:pass" \
    --mode="all" \
    --attempts=5 \
    --timeout=5 \
    --parse-images \
    --logs
```
