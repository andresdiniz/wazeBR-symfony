flowchart TD

subgraph group_views["Traffic views"]
  node_dashboard["Dashboard"]
  node_jam["Jam views<br/>[JamController.php]"]
  node_routes["Route views"]
  node_tv["TV wallboard<br/>[TvController.php]"]
  node_alerts["Alert views"]
  node_dashrepo["Dashboard queries"]
  node_jamrepo["Jam queries<br/>[JamRepository.php]"]
  node_routerepo["Route queries"]
  node_tvrepo["TV queries<br/>[TvRepository.php]"]
end

subgraph group_ingestion["Data ingestion"]
  node_fetchwaze["Waze imports"]
  node_wazesync["Waze synchronizer"]
  node_tvtfetch["TVT imports"]
  node_tvtsync["TVT synchronizer"]
end

subgraph group_partner["Partner feeds"]
  node_partnercontroller["Partner feed API"]
  node_feedservice["Feed service"]
  node_feedbuilder["CIFS builder"]
  node_feedmanager["Feed files"]
end

subgraph group_environment["Weather and water"]
  node_weatherfetch["Weather fetcher"]
  node_weather["Weather views"]
  node_cemadenhydro["Hydro imports"]
  node_cemadenrain["Rain imports"]
end

subgraph group_platform["Access and storage"]
  node_database[("MySQL database")]
  node_auth["Authentication<br/>[AuthController.php]"]
  node_user["User records<br/>[User.php]"]
  node_partner["Partner records<br/>[Partner.php]"]
end

node_viewer(("Viewer"))
node_operator(("Operator"))
node_waze{{"Waze services"}}
node_cemaden{{"Cemaden services"}}

node_viewer -->|"requests"| node_dashboard
node_viewer -->|"requests"| node_jam
node_viewer -->|"requests"| node_routes
node_viewer -->|"requests"| node_tv
node_viewer -->|"requests"| node_alerts
node_dashboard -->|"queries"| node_dashrepo
node_jam -->|"queries"| node_jamrepo
node_routes -->|"queries"| node_routerepo
node_tv -->|"queries"| node_tvrepo
node_dashrepo -->|"reads"| node_database
node_jamrepo -->|"reads"| node_database
node_routerepo -->|"reads"| node_database
node_tvrepo -->|"reads"| node_database
node_operator -.->|"triggers"| node_fetchwaze
node_fetchwaze -.->|"dispatches"| node_wazesync
node_wazesync -.->|"fetches"| node_waze
node_wazesync -.->|"writes"| node_database
node_operator -.->|"triggers"| node_tvtfetch
node_tvtfetch -.->|"dispatches"| node_tvtsync
node_tvtsync -.->|"fetches"| node_waze
node_tvtsync -.->|"writes"| node_database
node_weatherfetch -.->|"writes"| node_database
node_weather -.->|"reads"| node_database
node_cemadenhydro -.->|"fetches"| node_cemaden
node_cemadenhydro -.->|"writes"| node_database
node_cemadenrain -.->|"fetches"| node_cemaden
node_cemadenrain -.->|"writes"| node_database
node_viewer -->|"requests"| node_partnercontroller
node_partnercontroller -->|"builds feed"| node_feedservice
node_feedservice -.->|"formats"| node_feedbuilder
node_feedservice -.->|"reads"| node_database
node_operator -->|"signs in"| node_auth
node_auth -.->|"authenticates"| node_user
node_database -->|"stores"| node_user
node_database -->|"stores"| node_partner
node_operator -->|"manages feeds"| node_feedmanager

click node_dashboard "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Controller/DashboardController.php"
click node_jam "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Controller/JamController.php"
click node_routes "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Controller/RoutesController.php"
click node_tv "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Controller/TvController.php"
click node_alerts "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Controller/AlertController.php"
click node_dashrepo "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Repository/DashboardRepository.php"
click node_jamrepo "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Repository/JamRepository.php"
click node_routerepo "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Repository/RoutesRepository.php"
click node_tvrepo "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Repository/TvRepository.php"
click node_fetchwaze "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Command/FetchWazeFeedCommand.php"
click node_wazesync "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Service/WazeFeedSynchronizer.php"
click node_tvtfetch "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Command/FetchWazeTvtCommand.php"
click node_tvtsync "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Service/WazeTvtSynchronizer.php"
click node_weatherfetch "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Service/WeatherObservationFetcher.php"
click node_weather "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Controller/WeatherController.php"
click node_cemadenhydro "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Command/FetchCemadenHidroCommand.php"
click node_cemadenrain "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Command/FetchCemadenPluviometricCommand.php"
click node_partnercontroller "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Controller/PartnerFeedController.php"
click node_feedservice "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Service/PartnerFeedService.php"
click node_feedbuilder "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Service/CifsFeedBuilder.php"
click node_feedmanager "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Service/PartnerFeedManager.php"
click node_auth "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Controller/AuthController.php"
click node_user "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Entity/User.php"
click node_partner "https://github.com/andresdiniz/wazebr-symfony/blob/main/src/Entity/Partner.php"

classDef toneNeutral fill:#f8fafc,stroke:#334155,stroke-width:1.5px,color:#0f172a
classDef toneBlue fill:#dbeafe,stroke:#2563eb,stroke-width:1.5px,color:#172554
classDef toneAmber fill:#fef3c7,stroke:#d97706,stroke-width:1.5px,color:#78350f
classDef toneMint fill:#dcfce7,stroke:#16a34a,stroke-width:1.5px,color:#14532d
classDef toneRose fill:#ffe4e6,stroke:#e11d48,stroke-width:1.5px,color:#881337
classDef toneIndigo fill:#e0e7ff,stroke:#4f46e5,stroke-width:1.5px,color:#312e81
classDef toneTeal fill:#ccfbf1,stroke:#0f766e,stroke-width:1.5px,color:#134e4a
class node_dashboard,node_jam,node_routes,node_tv,node_alerts,node_dashrepo,node_jamrepo,node_routerepo,node_tvrepo,node_viewer toneBlue
class node_fetchwaze,node_wazesync,node_tvtfetch,node_tvtsync toneAmber
class node_partnercontroller,node_feedservice,node_feedbuilder,node_feedmanager toneMint
class node_weatherfetch,node_weather,node_cemadenhydro,node_cemadenrain toneRose
class node_database,node_auth,node_user,node_partner,node_operator,node_waze,node_cemaden toneIndigo

    wazeBR is a Symfony mobility-monitoring platform: users view traffic, incidents and analytics through web dashboards and map-oriented endpoints, while partner feeds and scheduled integrations bring in data. The graph separates traffic/route views, partner publishing, weather and water observations, and shared access/storage. Sampled code confirms controller-to-repository queries for jams and TV data, partner-feed service use, and route queries over persisted Waze data. Other capabilities are represented from the README and module names; their detailed wiring was not sampled.


Architecture overview
Read
wazeBR is a Symfony mobility-monitoring platform: users view traffic, incidents and analytics through web dashboards and map-oriented endpoints, while partner feeds and scheduled integrations bring in data. The graph separates traffic/route views, partner publishing, weather and water observations, and shared access/storage. Sampled code confirms controller-to-repository queries for jams and TV data, partner-feed service use, and route queries over persisted Waze data. Other capabilities are represented from the README and module names; their detailed wiring was not sampled.
