(() => {
    'use strict';

    const weekdays = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    const months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    window.s14DisplayDate = (value, includeTime = true) => {
        const raw = String(value ?? '').trim();
        if (!raw || raw.startsWith('0000-00-00')) return '—';
        const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T\s](\d{2}):(\d{2}))?/);
        if (!match) return raw;
        const year = Number(match[1]);
        const month = Number(match[2]);
        const day = Number(match[3]);
        const date = new Date(Date.UTC(year, month - 1, day));
        if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return raw;
        let formatted = `${weekdays[date.getUTCDay()]} ${match[3]} de ${months[month - 1]} del ${match[1]}`;
        if (includeTime && match[4] && (match[4] !== '00' || match[5] !== '00')) formatted += ` · ${match[4]}:${match[5]}`;
        return formatted;
    };
})();
