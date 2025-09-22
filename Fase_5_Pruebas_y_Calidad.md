# Fase 5: Estrategia de Pruebas y Calidad del Código

Una aplicación robusta requiere una estrategia de pruebas sólida. Este documento define un enfoque de múltiples capas para garantizar la calidad, estabilidad y fiabilidad de la nueva plataforma "Vendetta-Legacy".

## 1. Pruebas Unitarias

Las pruebas unitarias se centran en verificar la unidad de código más pequeña posible (una función, un componente) de forma aislada.

### 1.1. Backend (Lógica de Negocio)

-   **Herramienta:** **Vitest**. Es un framework de pruebas moderno, rápido y compatible con TypeScript.
-   **Enfoque:** Probaremos funciones puras que encapsulan la lógica de negocio crítica. Por ejemplo, los cálculos de producción de recursos, los algoritmos de combate, o las funciones de validación.
-   **Mocking de Base de Datos:** Para evitar que las pruebas unitarias dependan de una base de datos real, usaremos la librería **`prisma-mock`**. Nos permite simular el cliente de Prisma y definir los datos que devolverá para cada consulta, garantizando pruebas predecibles y rápidas.

**Ejemplo (Prueba de una función de cálculo):**
```typescript
// lib/calculations.test.ts
import { describe, it, expect } from 'vitest';
import { calculateResourceProduction } from './calculations';

describe('calculateResourceProduction', () => {
  it('should calculate production correctly for level 5 brewery', () => {
    const level = 5;
    const production = calculateResourceProduction('brewery', level);
    expect(production).toBe(150); // Ejemplo de valor esperado
  });
});
```

### 1.2. Frontend (Componentes de React)

-   **Herramientas:** **Vitest** y **React Testing Library (RTL)**.
-   **Enfoque:** RTL nos anima a probar los componentes de la misma manera que un usuario los utilizaría. En lugar de probar la implementación interna, probamos el resultado renderizado y la interacción. Se verificará que los componentes se rendericen correctamente según las props recibidas y que respondan a los eventos del usuario.

**Ejemplo (Prueba de un componente de botón):**
```tsx
// components/ui/Button.test.tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { Button } from './Button';
import { describe, it, expect, vi } from 'vitest';

describe('Button', () => {
  it('should render and be clickable', () => {
    const handleClick = vi.fn(); // Mock de la función onClick
    render(<Button onClick={handleClick}>Click Me</Button>);

    const buttonElement = screen.getByText(/Click Me/i);
    expect(buttonElement).toBeInTheDocument();

    fireEvent.click(buttonElement);
    expect(handleClick).toHaveBeenCalledTimes(1);
  });
});
```

## 2. Pruebas de Integración

Estas pruebas verifican la interacción entre varias partes del sistema, especialmente entre el frontend y el backend (API Routes).

-   **Herramienta:** **Mock Service Worker (MSW)**.
-   **Enfoque:** MSW intercepta las peticiones de red (`fetch`) que realizan nuestros componentes de React durante las pruebas. En lugar de llamar a la API real, MSW devuelve una respuesta simulada que nosotros definimos. Esto nos permite probar flujos completos del lado del cliente (ej. un usuario llena un formulario y hace clic en "Enviar") sin depender de un servidor de backend en ejecución.

**Ejemplo (Probar un formulario que llama a una API):**
Podemos simular que la API devuelve un `200 OK` en un caso de prueba y un `400 Bad Request` en otro, y verificar que nuestro componente de React maneja ambos escenarios correctamente (mostrando un mensaje de éxito o de error).

## 3. Pruebas End-to-End (E2E)

Las pruebas E2E son la capa más alta de la pirámide de testing. Simulan un flujo de usuario completo en un entorno lo más parecido posible al de producción.

-   **Herramientas:** **Playwright** (recomendado) o **Cypress**. Playwright ofrece una excelente automatización del navegador y es desarrollado por Microsoft.
-   **Enfoque:** Escribiremos scripts que automaticen el navegador para realizar acciones como si fueran un usuario real.

### 3.1. Flujos de Prueba Críticos

Se deben implementar al menos los siguientes flujos de E2E:

1.  **Registro y Primera Construcción:**
    -   Navegar a la página de registro.
    -   Crear una nueva cuenta.
    -   Iniciar sesión.
    -   Navegar a la página de edificios.
    -   Hacer clic en "Construir" en el primer edificio.
    -   Verificar que el edificio aparezca en la cola de construcción.

2.  **Ciclo de Ataque y Verificación de Informe:**
    -   Iniciar sesión con el "Jugador A".
    -   Enviar un ataque al "Jugador B".
    -   Cerrar sesión.
    -   Iniciar sesión con el "Jugador B".
    -   Navegar a la sección de informes de batalla.
    -   Verificar que un nuevo informe del ataque del "Jugador A" esté presente.

3.  **Unirse a una Familia (Alianza):**
    -   Iniciar sesión con un jugador sin familia.
    -   Navegar a la lista de familias.
    -   Enviar una solicitud para unirse a una familia.
    -   Iniciar sesión con el líder de la familia.
    -   Aceptar la solicitud del jugador.
    -   Verificar que el jugador ahora es miembro de la familia.

## 4. Integración Continua (CI/CD)

La automatización es clave para mantener la calidad a lo largo del tiempo.

-   **Herramienta:** **GitHub Actions**.
-   **Enfoque:** Crearemos un "workflow" de CI que se ejecutará en cada `push` a una rama o en cada `pull request`.

### 4.1. Pipeline Básico de CI

El pipeline (`.github/workflows/ci.yml`) realizará los siguientes pasos:

1.  **Checkout:** Descargar el código del repositorio.
2.  **Setup Environment:** Instalar Node.js y las dependencias del proyecto (`npm install`).
3.  **Linting:** Ejecutar ESLint para verificar la calidad y el estilo del código (`npm run lint`). El pipeline fallará si hay errores.
4.  **Unit & Integration Tests:** Ejecutar todas las pruebas de Vitest (`npm test`). El pipeline fallará si alguna prueba no pasa.
5.  **E2E Tests:**
    -   Iniciar la aplicación de Next.js en modo de producción.
    -   Ejecutar las pruebas de Playwright/Cypress contra la aplicación en ejecución.
    -   Este paso a menudo requiere una base de datos de prueba separada.
6.  **Build:** Ejecutar el comando de build de Next.js (`npm run build`) para asegurarse de que el proyecto compila correctamente para producción.

Este pipeline garantiza que cualquier cambio propuesto sea verificado automáticamente, reduciendo drásticamente la posibilidad de introducir regresiones en la rama principal.
