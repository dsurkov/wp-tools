#!/usr/bin/env bash
# wp-tools.sh — загрузчик функций WP Tools в текущую shell-сессию.
#
# Один и тот же диспетчер wp-tools.php исполняется через `curl | php`:
# функции здесь — тонкие обёртки, вся логика живёт в PHP.
#
#   eval "$(curl -sSL <url>/wp-tools.sh)"                          # разово
#   echo 'eval "$(curl -sSL <url>/wp-tools.sh)"' >> ~/.bashrc      # постоянно
#
# (source <(...) на некоторых сборках bash не читает пайп — eval надёжнее)
#
# После загрузки доступны функции (имена wp-* подставляются табом,
# как обычные команды):
#
#   wp-tools            — диспетчер: help / list / <tool> [args]
#   wp-packager         — паковка плагинов и тем в ZIP (таб: флаги + слаги)
#   wp-backupper        — бэкап файлов и БД (таб: --list, --help)
#   wp-info             — стек и конфиг WordPress (таб: --help)
#   wp-api-context      — контекст REST API, схемы таблиц (алиас wp-api)
#
# Вызывать — из корня сайта или любой поддиректории (корень WP ищется
# подъёмом вверх). Для подсказки слагов в wp-packager нужен WP-CLI (`wp`).

# URL диспетчера. Переопределить: WP_TOOLS_URL=<url> source wp-tools.sh
: "${WP_TOOLS_URL:=https://gist.githubusercontent.com/dsurkov/efafe42477aa571139401628d2fafd5a/raw/wp-tools.php}"

_wt_run() {
    curl -sSL "$WP_TOOLS_URL" | php -- "$@"
}

wp-tools()        { _wt_run "$@"; }
wp-packager()     { _wt_run packager "$@"; }
wp-backupper()    { _wt_run backupper "$@"; }
wp-info()         { _wt_run wp-info "$@"; }
wp-api-context()  { _wt_run api "$@"; }

# Короткие алиасы
wp-api()    { _wt_run api "$@"; }
wp-backup() { _wt_run backupper "$@"; }

# ---------------------------------------------------------------------------
# Таб-дополнение: показывает, что можно ввести дальше
# ---------------------------------------------------------------------------

_wp_tools_complete() {
    local cur prev tool
    cur="${COMP_WORDS[COMP_CWORD]}"
    prev="${COMP_WORDS[COMP_CWORD-1]}"
    tool="${COMP_WORDS[0]}"

    local tools="packager backupper wp-info api list help"
    local packager_opts="--list -l --all -a --help -h -?"
    local backupper_opts="--list -l --help -h -?"
    local info_opts="--help -h -?"
    local w used="" slugs="" s

    # аргументы, уже набранные в строке (чтобы не предлагать их повторно)
    for w in "${COMP_WORDS[@]:1}"; do
        used="$used $w"
    done

    case "$tool" in
        wp-tools)
            case "$prev" in
                packager|pkg)
                    COMPREPLY=( $(compgen -W "$packager_opts" -- "$cur") );;
                backupper|backup)
                    COMPREPLY=( $(compgen -W "$backupper_opts" -- "$cur") );;
                wp-info|info)
                    COMPREPLY=( $(compgen -W "$info_opts" -- "$cur") );;
                api|api-context)
                    COMPREPLY=( $(compgen -W "$info_opts" -- "$cur") );;
                *)
                    COMPREPLY=( $(compgen -W "$tools" -- "$cur") );;
            esac
            ;;
        wp-packager)
            if [[ "$cur" == -* ]]; then
                COMPREPLY=( $(compgen -W "$packager_opts" -- "$cur") )
            elif command -v wp >/dev/null 2>&1; then
                while read -r s; do
                    [[ -z "$s" ]] && continue
                    case " $used " in *" $s "*) ;; *) slugs="$slugs $s" ;; esac
                done <<< "$( { wp plugin list --field=slug 2>/dev/null; wp theme list --field=slug 2>/dev/null; } | sort -u )"
                COMPREPLY=( $(compgen -W "$slugs $packager_opts" -- "$cur") )
            else
                COMPREPLY=( $(compgen -W "$packager_opts" -- "$cur") )
            fi
            ;;
        wp-backupper|wp-backup)
            COMPREPLY=( $(compgen -W "$backupper_opts" -- "$cur") );;
        wp-info|wp-api|wp-api-context)
            COMPREPLY=( $(compgen -W "$info_opts" -- "$cur") );;
    esac
}

# zsh: включаем bash-совместимые completion (complete/compgen)
if [[ -n "$ZSH_VERSION" ]]; then
    autoload -U +X bashcompinit 2>/dev/null && bashcompinit 2>/dev/null
fi

if command -v complete >/dev/null 2>&1; then
    complete -F _wp_tools_complete wp-tools wp-packager wp-backupper wp-backup wp-info wp-api wp-api-context
fi
# ---------------------------------------------------------------------------
# Справка: что делает каждая команда (без флагов и с флагами)
# ---------------------------------------------------------------------------

_wt_help() {
    echo ""
    echo -e "\033[1;36mWP Tools\033[0m — что делает каждая команда:"
    echo ""
    echo -e "  \033[1;37mwp-info\033[0m                 стек WP/PHP/БД, лимиты, статусы, плагины, темы"
    echo -e "  \033[1;37mwp-info --help\033[0m          справка по wp-info"
    echo ""
    echo -e "  \033[1;37mwp-api-context\033[0m          локаль, Bookly, таблицы БД, API-namespaces"
    echo -e "  \033[1;37mwp-api-context <маска>\033[0m  схема таблиц, содержащих маску"
    echo ""
    echo -e "  \033[1;37mwp-backupper\033[0m            бэкап файлов + БД в _backups/"
    echo -e "  \033[1;37mwp-backupper --list\033[0m     список существующих бэкапов"
    echo ""
    echo -e "  \033[1;37mwp-packager <слаг>\033[0m      упаковать плагин/тему в ZIP"
    echo -e "  \033[1;37mwp-packager --all\033[0m       упаковать все плагины и темы"
    echo -e "  \033[1;37mwp-packager --list\033[0m      список установленных плагинов/тем"
    echo ""
    echo -e "  \033[1;37mwp-tools\033[0m                эта справка + список инструментов"
    echo -e "  \033[1;37mwp-tools list\033[0m           список инструментов"
    echo ""
    echo -e "  Таб: \033[38;5;244mwp-<TAB>\033[0m — команды, дальше флаги/слаги"
    echo -e "  Подробнее: \033[4;34mhttps://github.com/dsurkov/wp-tools/blob/main/README.md\033[0m"
    echo ""
}

wp-help() { _wt_help; }

_wt_help
