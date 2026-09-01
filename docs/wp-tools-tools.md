# WP Tools: инструменты backupper, wp-info, api и shell-загрузчик

## 1. Как это называется

WP Tools — набор CLI-инструментов для обслуживания WordPress: один
PHP-файл-диспетчер `wp-tools.php` (подкоманды) + bash-загрузчик `wp-tools.sh`
(функции в текущей сессии с таб-дополнением). Исполняется на сервере
напрямую из stdin (`curl | php`), с диска (`php wp-tools.php <tool>`) или
через функции (`wp-info`, `wp-backup`, …).

## 2. Стек

- PHP **7.2+** (CLI), без зависимостей; ZipArchive — только для `packager`/`backupper`.
- WordPress API через прямой require `wp-load.php` + `wp-admin/includes/plugin.php` + `wp-includes/theme.php` (WP-CLI не нужен).
- bash + `complete -F` (zsh — через `bashcompinit`).
- Файлы: `wp-tools.php` (диспетчер, ~750 строк), `wp-tools.sh` (загрузчик, ~100 строк), `README.md`.
- Репозиторий `dsurkov/wp-tools` (public) + публичный gist `efafe42477aa571139401628d2fafd5a` (raw-ссылка для `curl | php` и `eval "$(curl …)"`).

## 3. Техника

- Диспетчер: реестр `const WT_TOOLS = [ 'packager' => …, 'backupper' => …, 'wp-info' => …, 'api' => … ]`, каждая подкоманда — функция `tool_*( array $args ): int` с кодами выхода 0/1/2 (успех / фатальная / неизвестная команда).
- `wt_boot()`: подъём от `getcwd()` до `wp-load.php` (иначе `exit(1)`), require WP, возврат корня. `--help` любой подкоманды НЕ грузит WP.
- Guard PHP 7.2 — первая исполняемая инструкция после sapi-проверки (до любого `??`).
- **backupper**: ZIP всего корня WP в `_backups/` + дамп БД чисто-PHP: `SHOW TABLES` → `SHOW CREATE TABLE` → `SELECT *` с `esc_sql()`, INSERT-пакеты по таблицам, `SET NAMES`, `SET FOREIGN_KEY_CHECKS=0/1`; дамп пишется во временный `.sql` и кладётся в архив. Исключаются `_backups/`, `_packages/` и dot-каталоги (`.git`, `.svn`), но корневые dot-файлы (`.htaccess`) сохраняются.
- **wp-info**: версии WP/PHP/БД (MySQL vs MariaDB), лимиты PHP из CLI-ini или web-ini (LiteSpeed/fpm/apache2, поиск по версии PHP), статусы Debug/Cache/Cron, локаль/TZ, префикс/charset, пути; активные плагины (`get_option('active_plugins')`), активная тема + parent (child-детект через `$theme->parent()`).
- **api**: без аргумента — локаль/время, аддоны Bookly (`stripos($plugin,'bookly')`), таблицы БД (`SHOW TABLE STATUS`, префикс срезается), кастомные REST namespaces (ручной `new WP_REST_Server()` + `do_action('rest_api_init')`, фильтр по списку ядра). С аргументом — `SHOW TABLES LIKE %mask%` → для каждой таблицы `SHOW TABLE STATUS` + `SHOW COLUMNS` с выравниванием `str_pad`, подсветкой PRI/MUL.
- **Загрузчик**: функции `wp-tools`, `wp-packager`, `wp-backup`, `wp-info`, `wp-api-context` (+ алиас `wp-api`) — тонкие обёртки `curl -sSL $WP_TOOLS_URL | php -- <tool> "$@"`. `WP_TOOLS_URL` переопределяется (например, `file://` для локальной разработки).
- **Таб-дополнение**: `complete -F _wp_tools_complete` для всех `wp-*`; контекстная логика: `wp-tools` → инструменты/флаги, `wp-packager` → флаги + слаги (из `wp plugin list`/`wp theme list`, только при наличии WP-CLI, уже введённые слаги фильтруются), остальные → флаги.
- ANSI-цвета и спиннер «⏳ Загрузка...» + `\r\033[K` — перенесены из bash-функций пользователя (`wp eval`-сниппеты портированы нативно, `wp eval` убран).

## 4. Анимация

N/A (CLI). Единственный «живой» элемент — строка-спиннер «⏳ …», которая
затирается `\r\033[K` перед выводом результата.

## 5. Особенности

- **Один источник правды**: вся логика в PHP; bash-функции ничего не дублируют.
- **`source <(...)` не работает** на ряде сборок bash (пайп не читается) — документирован `eval "$(curl -sSL …)"`.
- Стаб-тесты: `/tmp/wptest` с фейковым `wp-load.php` (stub `$wpdb` + функции WP) — проверены все ветки инструментов без реального WP; реальный прогон на живом WP не делался.
- Лимит памяти: дамп большой БД и `--all` требуют `php -d memory_limit=-1`.
- `_backups/` содержит дамп БД (чувствительные данные) — публичная веб-папка, удалять после скачивания.

## 6. Дизайн

Параметры вывода (`wp-info`, `api`) взяты из bash-функций пользователя
(`wp-info()`, `wp-api-context()`): те же заголовки, цвета, порядок блоков,
имена полей («Среди и Конфиг» и пр.) — сохранены дословно. Формат флагов и
коды выхода — по конвенции диспетчера (как у `packager`).
