/**
 * Design system — Nexus Ops (Technical Suite)
 * Tokens extraidos da imagem de referencia do frontend.
 * Regra (CLAUDE.md s.12): cores e medidas vivem AQUI, nada hardcoded nas views.
 */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './app/Livewire/**/*.php',
    ],
    theme: {
        extend: {
            colors: {
                // Verde da marca — botoes primarios, logo, estados ativos
                verde: {
                    50: '#ECFDF3',
                    100: '#D1FADF',
                    200: '#A6F4C5',
                    300: '#6CE9A6',
                    400: '#32D583',
                    500: '#16A34A',
                    600: '#15803D',
                    700: '#166534',
                    800: '#14532D',
                    900: '#0A2A18',
                    950: '#050D08',
                },
                // Badge "EM CURSO" / estados de atencao
                aviso: {
                    100: '#FEF9C3',
                    200: '#FEF08A',
                    500: '#A16207',
                },
                // Estado de perigo — SLA em risco, eliminacoes
                perigo: {
                    100: '#FEE2E2',
                    200: '#FECACA',
                    500: '#DC2626',
                    600: '#B91C1C',
                },
                // Estado informativo — alertas neutros, links de info
                info: {
                    100: '#DBEAFE',
                    200: '#BFDBFE',
                    500: '#2563EB',
                    600: '#1D4ED8',
                },
                // Superficies e estrutura
                superficie: '#FFFFFF',
                fundo: '#F8FAFC',
                borda: '#E5E7EB',
                // Texto
                texto: {
                    forte: '#111827',
                    medio: '#6B7280',
                    fraco: '#9CA3AF',
                },
                // Sidebar escura (o degradê em si vive em backgroundImage.sidebar-grad)
                sidebar: {
                    ativo: '#0F3D24',
                    barra: '#22C55E',
                },
            },
            fontFamily: {
                sans: ['Poppins', 'system-ui', '-apple-system', 'sans-serif'],
            },
            boxShadow: {
                cartao: '0 1px 2px 0 rgba(16,24,40,.04), 0 1px 3px 0 rgba(16,24,40,.06)',
                topbar: '0 1px 0 0 rgba(16,24,40,.05)',
                botao: '0 1px 2px 0 rgba(16,24,40,.08)',
            },
            spacing: {
                sidebar: '290px',
            },
            backgroundImage: {
                'sidebar-grad':
                    'linear-gradient(180deg, #0A2A18 0%, #061008 58%, #020503 100%)',
            },
        },
    },
    plugins: [],
}
