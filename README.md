# wazeBR Symfony

Monitoramento de trã¡¡fego e mobilidade urbana em tempo real utilizando Symfony PHP.

## ãá¡¡ Funcionalidades

- **Monitoramento em Tempo Real**: Acompanhamento contã¡¡nuo de trã¡¡fego e incidentes
- **Mapas Interativos**: Visualizaçı¡Ì£o georreferenciada de dados
- **Alertas Inteligentes**: Notificaçı¡Ìµes automã¡¡ticas de incidentes
- **API RESTful**: Integraçı¡Ì£o com sistemas externos
- **Dashboard Analã¡¡tico**: Relatã¡¡rios e mÃ©tricas de desempenho

## ãá¡¡ Requisitos

- PHP 8.2+
- Symfony 7.x
- MySQL 8.0+ ou MariaDB 10.5+
- Composer
- Node.js e NPM (para assets)

## ãá¡¡ Instalaçı¡Ì£o

```bash
# Clonar repositã¡¡rio
git clone https://github.com/andresdiniz/wazeBR-symfony.git
cd wazeBR-symfony

# Instalar dependãªªncias PHP
composer install

# Instalar dependãªªncias Node (opcional, para build de assets)
npm install

# Configurar variã¡¡veis de ambiente
cp .env .env.local
# Editar .env.local com suas configuraçı¡Ìµes de banco de dados

# Criar banco de dados
php bin/console doctrine:database:create

# Executar migrations
php bin/console doctrine:migrations:migrate

# (Opcional) Carregar dados de exemplo
php bin/console doctrine:fixtures:load

# Iniciar servidor de desenvolvimento
symfony server:start
# ou
php -S localhost:8000 -t public
```

## ãá¡¡ Estrutura do Projeto

```
wazeBR-symfony/
ã¡¡ã¡¡config/              # Configuraçı¡Ìµes do Symfony
ã¡¡ã¡¡src/
ã¡¡ã¡¡ã¡¡ã¡¡Controller/     # Controladores (HomeController, etc.)
ã¡¡ã¡¡ã¡¡ã¡¡Entity/         # Entidades Doctrine
ã¡¡ã¡¡ã¡¡ã¡¡Repository/     # Repositã¡¡rios
ã¡¡ã¡¡ã¡¡ã¡¡Service/        # Serviços de negã¡¡cio
ã¡¡ã¡¡ã¡¡ã¡¡Command/        # Comandos CLI (imports, jobs)
ã¡¡ã¡¡templates/           # Templates Twig
ã¡¡ã¡¡ã¡¡ã¡¡home/           # Template da home page
ã¡¡ã¡¡assets/              # Assets (CSS, JS, imagens)
ã¡¡ã¡¡ã¡¡ã¡¡css/            # Folhas de estilo
ã¡¡ã¡¡ã¡¡ã¡¡js/             # JavaScript
ã¡¡ã¡¡public/              # Web root
ã¡¡ã¡¡var/                 # Cache, logs, dados temporã¡¡rios
ã¡¡ã¡¡import.php           # Modelo de importaçı¡Ì£o de dados
```

## ãá¡¡ Rotas Principais

| Rota | Nome | Descriçı¡Ì£o |
|------|------|------------|
| `/` | `home` | Pã¡¡gina inicial pãººblica |
| `/login` | `app_login` | Login de usuários |
| `/dashboard` | `dashboard` | Dashboard principal |
| `/api/traffic` | `api_traffic` | API de trã¡¡fego |

## ãá¡¡ Comandos Disponã¡¡veis

```bash
# Importar dados de trã¡¡fego
php bin/console app:import:traffic

# Importar dados de congestionamentos
php bin/console app:import:jams

# Limpar cache
php bin/console cache:clear

# Verificar rotas
php bin/console debug:router
```

## ãá¡¡ Variã¡¡veis de Ambiente

Edite `.env.local`:

```env
DATABASE_URL="mysql://user:password@127.0.0.1:3306/wazebr?serverVersion=8.0"
WAZE_API_URL="https://api.waze.com"
WAZE_API_KEY="sua-api-key-aqui"
MAILER_DSN="smtp://localhost:25"
```

## ãá¡¡ Desenvolvimento

### Criar novo controller

```bash
php bin/console make:controller
```

### Criar nova entidade

```bash
php bin/console make:entity
```

### Criar comando CLI

```bash
php bin/console make:command
```

## ãá¡¡ Testes

```bash
# Executar testes
php bin/phpunit

# Com coverage
php bin/phpunit --coverage-html var/coverage
```

## ãá¡¡ Deploy

### Shared Hosting

1. Upload de todos os arquivos exceto `vendor/` e `node_modules/`
2. Executar `composer install --no-dev --optimize-autoloader`
3. Configurar `.env.local` com dados de produçı¡Ì£o
4. Executar migrations
5. Configurar permissíµµes em `var/` e `public/`

### Docker (recomendado)

```bash
docker-compose up -d
```

## ãá¡¡ Tecnologias

- **Backend**: Symfony 7, PHP 8.2+, Doctrine ORM
- **Frontend**: Twig, CSS3, JavaScript ES6+
- **Banco de Dados**: MySQL 8.0+
- **API**: RESTful com autenticaçı¡Ì£o JWT
- **Ferramentas**: Composer, NPM, Git

## ãá¡¡ Licenã§§a

MIT License - ver arquivo [LICENSE](LICENSE)

## ãá¡¡ Contribuiçı¡Ì£o

1. Fork o projeto
2. Crie uma branch para sua feature (`git checkout -b feature/AmazingFeature`)
3. Commit suas mudanças (`git commit -m 'Add some AmazingFeature'`)
4. Push para a branch (`git push origin feature/AmazingFeature`)
5. Abra um Pull Request

## ãá¡¡ Contato

Andréªª Diniz - [@andresdiniz](https://github.com/andresdiniz)

Projeto: [https://github.com/andresdiniz/wazeBR-symfony](https://github.com/andresdiniz/wazeBR-symfony)

## ãá¡¡ Agradecimentos

- Waze API
- Symfony Community
- Todos os contribuidores
