# Fase 5: Estrategia de Pruebas y Calidad del Código (Revisado)

Una aplicación robusta requiere una estrategia de pruebas sólida. Este documento define un enfoque de múltiples capas para garantizar la calidad, estabilidad y fiabilidad de la nueva plataforma "Vendetta-Legacy", adaptado a la estructura de directorios `src/`.

## 1. Pruebas Unitarias

Las pruebas unitarias se centran en verificar la unidad de código más pequeña posible (una función, un componente) de forma aislada.

### 1.1. Lógica de Negocio y Server Actions

-   **Herramienta:** **Vitest**. Es un framework de pruebas moderno, rápido y compatible con TypeScript.
-   **Enfoque:**
    1.  **Funciones Puras:** Se probarán funciones de cálculo y lógica de negocio pura (ej. `runCombatSimulation`) de forma aislada.
    2.  **Server Actions:** Se pueden probar unitariamente importándolas directamente en el archivo de prueba. La capa de base de datos se mockeará con **`prisma-mock`** para simular las respuestas de Prisma y verificar que la acción se comporta como se espera bajo diferentes condiciones.

**Ejemplo (Prueba de una Server Action):**
```typescript
// src/lib/features/buildings/actions.test.ts
import { describe, it, expect, vi } from 'vitest';
import { upgradeBuildingAction } from './actions';
import { prisma } from '@/lib/core/prisma'; // Prisma se mockeará

// Mockear el módulo de Prisma
vi.mock('@/lib/core/prisma', () => ({
  prisma: {
    // Mock de las funciones de prisma que usa la acción
    building: { findUnique: vi.fn(), update: vi.fn() },
    newConstruction: { create: vi.fn() },
    $transaction: vi.fn(async (callback) => callback(prisma)),
  },
}));

// Mockear Next-Auth
vi.mock('next-auth/next');

describe('upgradeBuildingAction', () => {
  it('should throw an error if user is not authenticated', async () => {
    // Setup: simular que no hay sesión
    // ...
    await expect(upgradeBuildingAction('b1', 'oficina')).rejects.toThrow('No autenticado');
  });

  it('should successfully create a construction job', async () => {
    // Setup: simular una sesión válida y las respuestas de prisma
    // ...
    const result = await upgradeBuildingAction('b1', 'oficina');
    expect(result).toEqual({ success: true });
    expect(prisma.newConstruction.create).toHaveBeenCalledOnce();
  });
});
```

### 1.2. Componentes de React

-   **Herramientas:** **Vitest** y **React Testing Library (RTL)**.
-   **Enfoque:** Se probará que los componentes se rendericen correctamente y respondan a eventos del usuario. Las pruebas se ubicarán junto a los componentes que prueban.

**Ejemplo (Prueba de un componente de UI):**
```tsx
// src/components/ui/Button.test.tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { Button } from './Button';
import { describe, it, expect, vi } from 'vitest';

describe('Button', () => {
  it('should render and be clickable', () => {
    const handleClick = vi.fn();
    render(<Button onClick={handleClick}>Click Me</Button>);
    const buttonElement = screen.getByText(/Click Me/i);
    fireEvent.click(buttonElement);
    expect(handleClick).toHaveBeenCalledTimes(1);
  });
});
```

## 2. Pruebas de Integración

Dado que la nueva arquitectura prioriza Server Actions sobre API Routes, el enfoque de las pruebas de integración cambia. En lugar de mockear `fetch` con MSW para las mutaciones, probaremos la integración entre los **Client Components** y las **Server Actions** que invocan. Las pruebas unitarias de las Server Actions (vistas arriba) ya cubren gran parte de esta integración.

Para las pocas API Routes que queden (ej. crons, webhooks), el uso de `MSW` seguiría siendo válido si se necesitara probar un componente cliente que las consuma.

## 3. Pruebas End-to-End (E2E)

Este nivel de pruebas no cambia significativamente. Sigue siendo crucial para verificar los flujos de usuario completos en un entorno real.

-   **Herramientas:** **Playwright** (recomendado) o **Cypress**.
-   **Enfoque:** Los scripts de prueba simularán flujos de usuario completos en un navegador. Los flujos críticos a probar siguen siendo los mismos:
    1.  Registro y primera construcción.
    2.  Ciclo de ataque y verificación del informe de batalla.
    3.  Unirse a una familia (alianza).

## 4. Integración Continua (CI/CD)

El pipeline de CI/CD con **GitHub Actions** se mantiene, asegurando que todos los chequeos de calidad se ejecuten automáticamente.

**Archivo: `.github/workflows/ci.yml`**
```yaml
name: CI Pipeline

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v3

      - name: Setup Node.js
        uses: actions/setup-node@v3
        with:
          node-version: '20'

      - name: Install Dependencies
        run: npm install

      - name: Run Linting
        run: npm run lint

      - name: Run Unit & Integration Tests
        run: npm test

      # El build se ejecuta después de que los tests pasen
      - name: Run Build
        run: npm run build

      # Las pruebas E2E son opcionales en CI para mayor rapidez,
      # pero recomendadas antes de un despliegue a producción.
      # - name: Run E2E Tests
      #   run: npm run test:e2e
```
Este pipeline garantiza una alta calidad del código y previene la introducción de regresiones en la base del código.
