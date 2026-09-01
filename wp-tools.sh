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
