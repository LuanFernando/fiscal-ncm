<?php

namespace App\Controllers;

class NCM extends BaseController
{
    public function index()
    {
        return $this->response->setJSON([
            'warning' => 'Eu sei quem você é, mais você não tem permissão para acessar este recurso.'
        ]);
    }

    public function qualNcm()
    {

        # TODO: Valida token JWT, antes da requisição.

        # Resgata os dados da requisição
        $data = $this->request->getPost();

        # Valida se veio alguma solicitação
        if(empty($data['chat']) || !isset($data['chat'])){
            return $this->response->setJSON([
                'request' => $data['chat'],
                'response' => 'Nenhuma informação foi recebida.'
            ]);
        }

        # Verifica se a quantidade de token já bateu com o limite pré definido no .env
        $tokenAvailable = $this->tokenAvailable();

        if(!$tokenAvailable) {
            return $this->response->setJSON([
                'request' =>  $tokenAvailable ,
                'response' => 'Não foi possivel atender sua solicitação, seu consumo de tokens excedeu.'
            ]);
        }
        
        # Chama o método que irá construir o prompt.
        $prompt =  $this->buildPrompt($data['chat']);

        # Resgata a key para chamar a API da IA.
        $apiKey = $this->getApiKey('gemini');

        # Faz requisição Gemini
        $responseAPI = $this->generateContentWithGemini($prompt, $apiKey);

        # Salva em um log a informação de consumo de tokens.
        $this->saveLogTokens($responseAPI);

        # TODO: Armazena no banco de dados a quantidade de tokens consumidas.

        if(isset($responseAPI) && !empty($responseAPI)) {
            return $this->response->setJSON([
                'request' => $data['chat'],
                'response' => $responseAPI['candidates'][0]['content']['parts'][0]['text']
            ]);
        } else {
            return $this->response->setJSON([
                'request' => $data['chat'],
                'response' => 'Não foi possivel atender sua solicitação.'
            ]);
        }
    }

    /**
     * @param string $context
     * @param string $action
     * @return string $prompt
     * */
    public function buildPrompt($context, $action = null)
    {
        if($action == 'codigo-ncm'){
            $prompt = "
                    Você é um especialista em classificação fiscal e tributária, com foco exclusivo na interpretação e explicação de códigos NCM (Nomenclatura Comum do Mercosul).

                    Siga rigorosamente as instruções abaixo:

                    1. **Entrada válida**:
                    - Aceite **apenas códigos NCM numéricos com 8 dígitos** (ex: 42029200).
                    - Caso o usuário informe qualquer outra coisa — como nome de produto, texto descritivo, caracteres especiais ou quantidade diferente de dígitos —, responda com:
                        > 'Só são aceitos códigos NCM numéricos com 8 dígitos.'

                    2. **Saída esperada**:
                    - Ao receber um código NCM válido, retorne **apenas as informações correspondentes a esse código**, sem expandir o contexto ou fazer inferências.
                    - A resposta deve conter:
                        - Código NCM consultado
                        - Descrição oficial da Receita Federal ou da Tabela NCM vigente
                        - Categoria ou capítulo da NCM
                        - Observações técnicas relevantes (se houver), como exceções ou ex-tarifários

                    3. **Nada além do necessário**:
                    - Não forneça informações adicionais, sugestões de uso, classificações alternativas, ou explicações extensas.  
                    - Seja **objetivo, técnico e direto**.

                    4. **Ambiguidade ou erro**:
                    - Se a entrada for ambígua ou não for possível localizar o código, responda com:
                        > 'Código NCM inválido ou não encontrado na base de dados.'

                    Nunca tente adivinhar, interpretar produtos ou corrigir formatos. Trabalhe **exclusivamente com códigos válidos, exatos e numéricos**.

                    ### AQUI ESTÁ A CÓDIGO INFORMADO PELO USUÁRIO: $context
                ";
        } else {
            $prompt = "
                Você é um especialista em classificação fiscal e tributária com foco exclusivo na identificação do código NCM (Nomenclatura Comum do Mercosul) de produtos comercializados no Brasil.
    
                Siga rigorosamente as instruções abaixo ao responder:
    
                1. **Contexto Limitado**: Responda somente a perguntas relacionadas à consulta de código NCM.  
                - Caso a pergunta não tenha correlação com NCM, diga: 'Não posso te ajudar com esta informação.'
    
                2. **Multiplas Possibilidades de NCM**:  
                - Se houver mais de um código NCM possível para o produto informado, **apresente todos os códigos relevantes**.
                - Para cada código, forneça **descrições detalhadas, exemplos práticos de aplicação e critérios técnicos** que ajudem o usuário a **distinguir o código mais apropriado** de acordo com as características do produto, como finalidade, material, composição, processo de fabricação, ou segmento de mercado.
    
                3. **Pedidos Ambíguos ou Incompletos**:  
                - Se a solicitação estiver confusa, vaga ou imprecisa, responda com: 'Não consegui entender, pode ser mais claro?'
    
                4. **Linguagem Técnica e Clara**:  
                - Use uma linguagem **simples, porém técnica e objetiva**, adequada tanto para usuários leigos quanto para profissionais da área fiscal.
                - Inclua **palavras-chave e termos fiscais relevantes**, como 'alíquota', 'incidência', 'ex-tarifário', 'substituição tributária', etc., sempre que forem pertinentes.
    
                5. **Estrutura Recomendada da Resposta**:
                - Nome do produto solicitado
                - Código(s) NCM sugerido(s)
                - Descrição oficial do código NCM
                - Quando aplicável, critérios para escolha entre os códigos possíveis
                - Observações fiscais relevantes (se houver)
    
                **Atenção**: Nunca faça suposições sem justificativa técnica. Baseie-se em descrições específicas do produto.
    
                Exemplo de uso:
                'Qual o NCM para uma mochila escolar de poliéster?'
    
                ### AQUI ESTÁ A PERGUNTA DO USUÁRIO: $context
            ";
        }

        return $prompt;
    } 

    /**
     * @param string $model
     * @return string|null $key
     * */
    public function getApiKey($model)
    {
        $key = null;

        if($model == 'gemini'){
            $key = env('GOOGLE_GEMINI_KEY');
        } else if($model == 'gpt') {
            $key = env('OPENAI_GPT');
        }

        return $key;
    } 

    /**
     * GOOGLE GEMINI AI
     * @param string $prompt
     * @param string $apiKey
     * @return json $result
     * promptTokenCount : Quantos tokens foram usados na entrada enviada para a API (seu prompt). 518
     * candidatesTokenCount : Quantos tokens foram usados na resposta gerada pelo modelo. 691
     * totalTokenCount : Soma de tudo: entrada + resposta. Aqui: 518 + 691 = 1209
     * promptTokensDetails : Mostra o tipo de conteúdo enviado (ex: TEXT, IMAGE) e sua contagem de tokens.
     * candidatesTokensDetails : Mostra o tipo de conteúdo da resposta e quantos tokens foram usados.
     * 
     * */ 
    public function generateContentWithGemini($prompt, $apiKey)
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key={$apiKey}";

        $payload = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => "$prompt"]
                    ]
                ]
            ]
        ];

        // Inicializa o cURL
        $ch = curl_init($url);

        // Configurações do cURL
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Ignora a verificação SSL - segundo a polita da nominatim precisa passar estes cabeçalhos
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        // Executa a solicitação
        $response = curl_exec($ch);

        // Verifica erros no cURL
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['error' => $error];
        }

        curl_close($ch);

        // Converte a resposta para JSON
        $data = json_decode($response, true);
        
        return $data;
    }

    /**
     * Salva log de consumo de tokens da API Gemini.
     *
     * @param array|object|string $response_api JSON decodificado ou string JSON
     * @return bool $save
     */
    public function saveLogTokens($response_api): bool
    {
        $save = false;

        // Caminho absoluto 
        $pathLogs = WRITEPATH . 'logs/consumption.log';

        // Garante que a pasta de logs existe
        if (!file_exists(dirname($pathLogs))) {
            mkdir(dirname($pathLogs), 0755, true);
        }

        // Verifica se é string JSON e decodifica
        if (is_string($response_api)) {
            $response_api = json_decode($response_api, true);
        }

        if (is_array($response_api) && isset($response_api['usageMetadata'])) {
            $tokens = $response_api['usageMetadata'];

            $log = sprintf(
                "[%s] Tokens usados: prompt=%d, resposta=%d, total=%d\n",
                date('Y-m-d H:i:s'),
                $tokens['promptTokenCount'] ?? 0,
                $tokens['candidatesTokenCount'] ?? 0,
                $tokens['totalTokenCount'] ?? 0
            );

            // Salva no arquivo
            $save = error_log($log, 3, $pathLogs);
        }

        return $save;
    }

    /**
     * @return bool
     * */ 
    public function tokenAvailable(): bool
    {
        $limitToken = (int) env('LIMIT_TOKEN', 100000); // valor padrão
        $sumToken = 0;
    
        $pathLogs = WRITEPATH . 'logs/consumption.log';
    
        if (!file_exists($pathLogs)) {
            return true;
        }
    
        $lines = file($pathLogs, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
        foreach ($lines as $line) {
            if (strpos($line, 'total=') !== false) {
                preg_match('/total=(\d+)/', $line, $matches);
                if (isset($matches[1])) {
                    $sumToken += (int) $matches[1];
                }
            }
        }
    
        return $sumToken < $limitToken;
    }


    /**
     * 
     * */
    public function codigoNcm()
    {
        
        # TODO: Valida token JWT, antes da requisição.

        # Resgata os dados da requisição
        $data = $this->request->getPost();

        # Valida se veio alguma solicitação
        if(empty($data['ncm']) || !isset($data['ncm'])){
            return $this->response->setJSON([
                'request' => $data['ncm'],
                'response' => 'Nenhuma informação foi recebida.'
            ]);
        }

        $codNCM = str_replace(".", "", $data['ncm']);

        // Apenas o código
        if(!is_numeric($codNCM)){
            return $this->response->setJSON([
                'request' => $data['ncm'],
                'response' => 'Informe apenas o código sem caractere especiais.'
            ]);
        }

        # Verifica se a quantidade de token já bateu com o limite pré definido no .env
        $tokenAvailable = $this->tokenAvailable();

        if(!$tokenAvailable) {
            return $this->response->setJSON([
                'request' =>  $tokenAvailable ,
                'response' => 'Não foi possivel atender sua solicitação, seu consumo de tokens excedeu.'
            ]);
        }

        # Chama o método que irá construir o prompt.
        $prompt =  $this->buildPrompt($codNCM, 'codigo-ncm');

        # Resgata a key para chamar a API da IA.
        $apiKey = $this->getApiKey('gemini');

        # Faz requisição Gemini
        $responseAPI = $this->generateContentWithGemini($prompt, $apiKey);

        # Salva em um log a informação de consumo de tokens.
        $this->saveLogTokens($responseAPI);

        # TODO: Armazena no banco de dados a quantidade de tokens consumidas.

        if(isset($responseAPI) && !empty($responseAPI)) {
            return $this->response->setJSON([
                'request' => $data['ncm'],
                'response' => $responseAPI['candidates'][0]['content']['parts'][0]['text']
            ]);
        } else {
            return $this->response->setJSON([
                'request' => $data['ncm'],
                'response' => 'Não foi possivel atender sua solicitação.'
            ]);
        }
    } 
    

}
