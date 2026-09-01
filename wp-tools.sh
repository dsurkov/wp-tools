#!/usr/bin/env bash
# wp-tools.sh — загрузчик WP Tools в текущую shell-сессию.
#
# Одна функция-диспетчер wp-tools с подкомандами; вся логика живёт
# в PHP (wp-tools.php), функции здесь — тонкие обёртки curl | php.
#
#   eval "$(curl -sSL <url>/wp-tools.sh)"                          # разово
#   echo 'eval "$(curl -sSL <url>/wp-tools.sh)"' >> ~/.bashrc      # постоянно
#
# Подкоманды (wp-tools <TAB> подсказывает следующий уровень):
#   wp-tools packager <слаг>   упаковать плагин/тему в ZIP
#   wp-tools packager --all    упаковать все плагины и темы
#   wp-tools stack             стек и конфиг WordPress
#   wp-tools api [маска]       контекст API / схема таблиц
#   wp-tools backup            бэкап файлов + БД (по умолчанию)
#   wp-tools backup db|files   только дамп БД / только файлы сайта
#   wp-tools backup --list     список существующих бэкапов
#   wp-tools list              список инструментов
#   wp-tools help              эта справка
#   wp-tools update            перекачать функции (обновление)
#
# Вызывать — из корня сайта или любой поддиректории (корень WP ищется
# подъёмом вверх). Для подсказки слагов в packager нужен WP-CLI (`wp`).

# URL диспетчера. Переопределить: WP_TOOLS_URL=<url> source wp-tools.sh
: "${WP_TOOLS_URL:=https://gist.githubusercontent.com/dsurkov/efafe42477aa571139401628d2fafd5a/raw/wp-tools.php}"

# URL самого загрузчика (для wp-tools update).
: "${WP_TOOLS_SH_URL:=https://gist.githubusercontent.com/dsurkov/efafe42477aa571139401628d2fafd5a/raw/wp-tools.sh}"

# Версия загрузчика; обновляется вместе с wp-tools.php
WT_VERSION="0.3.0"

_wt_run() {
    curl -sSL "$WP_TOOLS_URL" | php -- "$@"
}

wp-tools() {
    local cmd="${1:-}"
    if [[ -n "$1" ]]; then shift; fi

    case "$cmd" in
        version|--version|-V)
            echo -e "WP Tools v${WT_VERSION} (сессия). Проверка свежести: \033[38;5;244mwp-tools update\033[0m"
            ;;
        update)
            # сравнить версию с источником; если изменилась — переопределить функции
            local sh new_ver old_ver="$WT_VERSION"
            sh="$(curl -sSL "$WP_TOOLS_SH_URL")" || { echo "wp-tools update: не удалось загрузить загрузчик" >&2; return 1; }
            new_ver="$(grep -m1 '^WT_VERSION=' <<< "$sh" | cut -d'"' -f2)"
            if [[ -n "$new_ver" && "$new_ver" != "$old_ver" ]]; then
                WP_TOOLS_QUIET=1 eval "$sh"
                echo -e "\033[1;32mWP Tools\033[0m обновлены: v${old_ver} → v${new_ver}"
            else
                echo -e "WP Tools уже актуальна: v${old_ver}"
            fi
            ;;
        list|--list|-l)       _wt_run list ;;
        help|--help|-h|-\?|'') _wt_help ;;
        packager|pkg)         _wt_run packager "$@" ;;
        stack|info)           _wt_run wp-info "$@" ;;
        api)                  _wt_run api "$@" ;;
        backup)
            case "${1:-}" in
                db)          _wt_run backup db ;;
                files)       _wt_run backup files ;;
                all|'')      _wt_run backup all ;;
                --list|-l)   _wt_run backup --list ;;
                --help|-h|-\?) _wt_run backup --help ;;
                *)           _wt_run backup "$@" ;;
            esac
            ;;
        *) _wt_run "$cmd" "$@" ;;
    esac
}

# ---------------------------------------------------------------------------
# Таб-дополнение: подсказывает следующий уровень вложенности
# ---------------------------------------------------------------------------

_wp_tools_complete() {
    local cur prev tool
    cur="${COMP_WORDS[COMP_CWORD]}"
    prev="${COMP_WORDS[COMP_CWORD-1]}"
    tool=""
    COMPREPLY=()

    local level1="packager stack api backup list help update version --list -l --help -h -?"
    local packager_opts="--list -l --all -a --help -h -?"
    local backup_opts="db files all --list -l --help -h -?"
    local info_opts="--help -h -?"
    local w used="" slugs="" s

    # в какой подкоманде мы находимся + уже набранные слова
    for w in "${COMP_WORDS[@]:1}"; do
        used="$used $w"
        case "$w" in
            packager|pkg)     tool="packager";;
            backup) tool="backup";;
            stack|info)       tool="stack";;
            api)              tool="api";;
        esac
    done

    case "$tool" in
        packager)
            if [[ "$cur" == -* ]]; then
                COMPREPLY=( $(compgen -W "$packager_opts" -- "$cur") )
            elif command -v wp >/dev/null 2>&1; then
                while read -r s; do
                    [[ -z "$s" ]] && continue
                    case " $used " in *" $s "*) ;; *) slugs="$slugs $s" ;; esac
                done <<< "$( { wp plugin list --field=slug; wp theme list --field=slug; } 2>/dev/null | grep -vE '^Deprecated' | sort -u )"
                COMPREPLY=( $(compgen -W "$slugs $packager_opts" -- "$cur") )
            else
                COMPREPLY=( $(compgen -W "$packager_opts" -- "$cur") )
            fi
            ;;
        backup)
            # только сразу после `backup`: db / files / all / флаги
            if [[ "$prev" == "backup" ]]; then
                COMPREPLY=( $(compgen -W "$backup_opts" -- "$cur") )
            fi
            ;;
        stack|api)
            COMPREPLY=( $(compgen -W "$info_opts" -- "$cur") );;
        "")
            if [[ "$prev" == "wp-tools" ]]; then
                COMPREPLY=( $(compgen -W "$level1" -- "$cur") )
            fi
            ;;
    esac
}

# zsh: включаем bash-совместимые completion (complete/compgen)
if [[ -n "$ZSH_VERSION" ]]; then
    autoload -U +X bashcompinit 2>/dev/null && bashcompinit 2>/dev/null
fi

if command -v complete >/dev/null 2>&1; then
    complete -F _wp_tools_complete wp-tools
fi

# ---------------------------------------------------------------------------
# Справка: что делает каждая команда (печатается при загрузке)
# ---------------------------------------------------------------------------

_wt_help() {
    echo ""
    echo -e "\033[1;36mWP Tools v${WT_VERSION}\033[0m — что делает каждая команда:"
    echo ""
    echo -e "  \033[1;37mwp-tools packager <слаг>\033[0m   упаковать плагин/тему в ZIP"
    echo -e "  \033[1;37mwp-tools packager --all\033[0m    упаковать все плагины и темы"
    echo -e "  \033[1;37mwp-tools stack\033[0m             стек WP/PHP/БД, лимиты, плагины, темы"
    echo -e "  \033[1;37mwp-tools api\033[0m               локаль, Bookly, таблицы, API-namespaces"
    echo -e "  \033[1;37mwp-tools api <маска>\033[0m       схема таблиц, содержащих маску"
    echo ""
    echo -e "  \033[1;37mwp-tools backup\033[0m            бэкап файлов + БД (по умолчанию)"
    echo -e "  \033[1;37mwp-tools backup db\033[0m         только дамп БД"
    echo -e "  \033[1;37mwp-tools backup files\033[0m      только файлы сайта"
    echo -e "  \033[1;37mwp-tools backup --list\033[0m     список существующих бэкапов"
    echo ""
    echo -e "  \033[1;37mwp-tools list\033[0m              список инструментов"
    echo -e "  \033[1;37mwp-tools version\033[0m           текущая версия"
    echo -e "  \033[1;37mwp-tools update\033[0m            обновить до свежей версии (показывает 0.x → 0.y)"
    echo ""
    echo -e "  Таб: \033[38;5;244mwp-tools <TAB>\033[0m — команды, дальше флаги/слаги"
    echo -e "  Подробнее: \033[4;34mhttps://github.com/dsurkov/wp-tools/blob/main/README.md\033[0m"
    echo ""
}

# Баннер печатаем только при обычной загрузке; wp-tools update ставит WP_TOOLS_QUIET=1
if [[ -z "${WP_TOOLS_QUIET:-}" ]]; then
    _wt_help
fi
