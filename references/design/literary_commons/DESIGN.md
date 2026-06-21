---
name: Literary Commons
colors:
  surface: '#fbf9f5'
  surface-dim: '#dbdad6'
  surface-bright: '#fbf9f5'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f5f3ef'
  surface-container: '#efeeea'
  surface-container-high: '#eae8e4'
  surface-container-highest: '#e4e2de'
  on-surface: '#1b1c1a'
  on-surface-variant: '#414844'
  inverse-surface: '#30312e'
  inverse-on-surface: '#f2f0ed'
  outline: '#727974'
  outline-variant: '#c1c8c2'
  surface-tint: '#446555'
  primary: '#274738'
  on-primary: '#ffffff'
  primary-container: '#3e5f4f'
  on-primary-container: '#b3d7c3'
  inverse-primary: '#aacfbb'
  secondary: '#565f67'
  on-secondary: '#ffffff'
  secondary-container: '#dae4ed'
  on-secondary-container: '#5c656d'
  tertiary: '#5b3912'
  on-tertiary: '#ffffff'
  tertiary-container: '#765027'
  on-tertiary-container: '#f9c592'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#c6ebd6'
  primary-fixed-dim: '#aacfbb'
  on-primary-fixed: '#002114'
  on-primary-fixed-variant: '#2d4d3e'
  secondary-fixed: '#dae4ed'
  secondary-fixed-dim: '#bec8d1'
  on-secondary-fixed: '#141d23'
  on-secondary-fixed-variant: '#3f484f'
  tertiary-fixed: '#ffdcbd'
  tertiary-fixed-dim: '#f0bd8b'
  on-tertiary-fixed: '#2c1600'
  on-tertiary-fixed-variant: '#623f18'
  background: '#fbf9f5'
  on-background: '#1b1c1a'
  surface-variant: '#e4e2de'
typography:
  headline-xl:
    fontFamily: Playfair Display
    fontSize: 48px
    fontWeight: '700'
    lineHeight: '1.2'
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Playfair Display
    fontSize: 32px
    fontWeight: '700'
    lineHeight: '1.3'
  headline-lg-mobile:
    fontFamily: Playfair Display
    fontSize: 28px
    fontWeight: '700'
    lineHeight: '1.3'
  headline-md:
    fontFamily: Playfair Display
    fontSize: 24px
    fontWeight: '600'
    lineHeight: '1.4'
  body-lg:
    fontFamily: Work Sans
    fontSize: 18px
    fontWeight: '400'
    lineHeight: '1.6'
  body-md:
    fontFamily: Work Sans
    fontSize: 16px
    fontWeight: '400'
    lineHeight: '1.6'
  label-md:
    fontFamily: Work Sans
    fontSize: 14px
    fontWeight: '500'
    lineHeight: '1.4'
    letterSpacing: 0.01em
  label-sm:
    fontFamily: Work Sans
    fontSize: 12px
    fontWeight: '600'
    lineHeight: '1.2'
    letterSpacing: 0.03em
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  base: 8px
  xs: 4px
  sm: 12px
  md: 24px
  lg: 48px
  xl: 80px
  container-max: 1200px
  gutter: 24px
---

## Brand & Style

The design system is anchored in the atmosphere of a well-curated local bookstore: warm, quiet, and intellectually stimulating. It balances the timeless authority of a library with the modern accessibility of a digital community. The target audience includes avid readers, students, and collectors who value clarity and a sense of "physicality" in digital interfaces.

The aesthetic follows a **Modern Minimalist** approach with **Tactile** undertones. It avoids the coldness of pure white pixels by utilizing "paper" tones and subtle depth. The interface should feel intentional and calm, encouraging long-form reading and thoughtful browsing rather than high-frequency engagement loops.

## Colors

The palette is derived from classic book materials: aged paper, forest-green cloth bindings, and deep ink.

- **Primary (Bookstore Green):** Used for primary actions, success states, and branding elements. It evokes a sense of growth and calm.
- **Secondary (Scholarly Navy):** Used for navigation, headers, and secondary buttons. It provides a grounded, authoritative contrast to the primary green.
- **Neutral (Paper White):** The core background color. It is a warm, slightly yellowed off-white that reduces eye strain compared to pure #FFFFFF.
- **Accents (Muted Tones):** For category tags, use a range of desaturated earth tones (dusty rose, sage, ochre, slate) to differentiate genres without creating visual noise.

## Typography

This design system uses a high-contrast typographic pairing to signal the transition between "content" and "interface."

**Playfair Display** is reserved for headlines and editorial moments. Its high contrast and elegant serifs evoke the feeling of a printed title page.

**Work Sans** serves as the functional workhorse for body copy and UI elements. Its clean, open apertures ensure legibility on small screens and provide a modern, reliable counterpoint to the decorative serif. 

For optimal reading, body text should maintain a maximum line length of 70 characters. Headlines should use tighter letter spacing to maintain a cohesive visual block.

## Layout & Spacing

The design system utilizes a **Fixed Grid** model for desktop to ensure the reading experience feels contained and organized, shifting to a fluid model for mobile devices.

- **Desktop (1200px+):** 12-column grid with 24px gutters. Content is centered with wide margins to create a "generous" feel.
- **Tablet (768px - 1199px):** 8-column grid with 20px gutters and 40px side margins.
- **Mobile (<768px):** 4-column grid with 16px gutters and 16px side margins.

Vertical rhythm follows an 8px baseline. Large sections of content should be separated by 'xl' spacing (80px) to allow the "paper" background to breathe.

## Elevation & Depth

This design system eschews heavy shadows in favor of **Tonal Layers** and **Soft Ambient Occlusion**.

- **Level 0 (Base):** The Paper White background (#FDFBF7).
- **Level 1 (Cards/Surface):** White (#FFFFFF) with a very thin 1px border in a slightly darker paper tone (#EAE7E0).
- **Level 2 (Modals/Overlays):** White (#FFFFFF) with a soft, diffused shadow (0px 10px 30px rgba(35, 44, 51, 0.08)). 

Depth is primarily communicated through color shifts and hairline borders rather than dramatic lighting, mimicking the flat layers of a book on a desk.

## Shapes

The shape language is **Soft**. A 4px (0.25rem) radius is applied to most UI components to remove the harshness of sharp corners while maintaining a professional, structured appearance. 

- **Standard Elements:** 4px radius (Buttons, Input Fields, Cards).
- **Large Elements:** 8px radius (Modals, Feature Sections).
- **Tags/Chips:** Fully rounded (pill-shaped) to distinguish them from functional UI buttons.

## Components

### Buttons
Primary buttons use the Bookstore Green with white text. Secondary buttons use a Scholarly Navy outline. Use a generous horizontal padding (24px) to ensure buttons feel substantial and easy to interact with.

### Cards
Cards are the primary container for book listings. They should feature a clean vertical stack: book cover image at the top, followed by the title (Serif), author (Sans-serif), and category tags. Cards use a 1px "Paper" border rather than a shadow to maintain a clean grid.

### Tab Navigation
Tabs use a minimal underlined style. The active state is indicated by a 2px Scholarly Navy bar below the label. Text remains centered and uses the `label-md` typographic style.

### Category Tags
Tags use a muted color palette (e.g., #E8F0EA for 'Nature', #F4EAE0 for 'History'). The text color should be a darkened version of the background color to maintain accessibility while keeping the look "colorful but muted."

### Modals
Modals appear centered with a deep navy overlay at 40% opacity. The modal container uses the Level 2 elevation and 8px rounded corners. Headers within modals must use the `headline-md` serif font.

### Input Fields
Inputs use a white background with a subtle 1px border. On focus, the border transitions to Bookstore Green. Place labels above the input using the `label-sm` style.