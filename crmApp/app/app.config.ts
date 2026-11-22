// Kolory i domyślne rozmiary Nuxt UI. Odcienie pod kontrast WCAG 2.1 AA są nadpisane w assets/css/main.css.
export default defineAppConfig({
  ui: {
    colors: { primary: 'blue', secondary: 'indigo', success: 'green', info: 'sky', warning: 'amber', error: 'red', neutral: 'slate' },
    button: { defaultVariants: { size: 'lg' } },
    input: { defaultVariants: { size: 'lg' } },
    select: { defaultVariants: { size: 'lg' } },
    selectMenu: { defaultVariants: { size: 'lg' } },
    textarea: { defaultVariants: { size: 'lg' } },
  },
})
